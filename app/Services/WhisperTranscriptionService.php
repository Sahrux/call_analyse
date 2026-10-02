<?php

namespace App\Services;

use App\Exceptions\TranscriptionFailedException;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Sleep;

class WhisperTranscriptionService
{
    private const GEMINI_FILES_UPLOAD_URL = 'https://generativelanguage.googleapis.com/upload/v1beta/files';

    private const GEMINI_INTERACTIONS_URL = 'https://generativelanguage.googleapis.com/v1beta/interactions';

    private const GEMINI_FILES_BASE_URL = 'https://generativelanguage.googleapis.com/v1beta';

    private const GEMINI_INTERACTION_MAX_ATTEMPTS = 3;

    /**
     * Seconds to wait after the 1st and 2nd transient failures (2s, then 5s).
     * The third value is unused at the current attempt cap.
     *
     * @var list<int>
     */
    private const GEMINI_INTERACTION_BACKOFF_SECONDS = [2, 5, 10];

    /**
     * @return array{courtesy_score: int, rudeness_score: int, emotional_tone_score: int, inappropriate_expressions: list<array{speaker?: string, text?: string}>|null}
     */
    public function analyzeCallQuality(string $transcript): array
    {
        $apiKey = config('whisper.gemini_api_key');

        if (! is_string($apiKey) || $apiKey === '') {
            throw new TranscriptionFailedException('Gemini API key is not configured.');
        }

        $interactionResponse = $this->postGeminiInteraction($apiKey, [
            'model' => $this->geminiModel(),
            'input' => [
                [
                    'type' => 'text',
                    'text' => $this->buildCallQualityPrompt($transcript),
                ],
            ],
            'response_format' => [
                'type' => 'text',
                'mime_type' => 'application/json',
                'schema' => [
                    'type' => 'object',
                    'properties' => [
                        'courtesy_score' => ['type' => 'integer', 'minimum' => 0, 'maximum' => 100],
                        'rudeness_score' => ['type' => 'integer', 'minimum' => 0, 'maximum' => 100],
                        'emotional_tone_score' => ['type' => 'integer', 'minimum' => 0, 'maximum' => 100],
                        'inappropriate_expressions' => [
                            'type' => 'array',
                            'items' => [
                                'type' => 'object',
                                'properties' => [
                                    'text' => ['type' => 'string'],
                                    'speaker' => [
                                        'type' => 'string',
                                        'enum' => ['operator', 'musteri'],
                                    ],
                                ],
                                'required' => ['speaker'],
                            ],
                        ],
                    ],
                    'required' => [
                        'courtesy_score',
                        'rudeness_score',
                        'emotional_tone_score',
                    ],
                ],
            ],
        ], 'call quality analysis');

        if ($interactionResponse->failed()) {
            throw new TranscriptionFailedException($this->resolveErrorMessage($interactionResponse->json(), $interactionResponse->body(), 'Gemini call quality analysis request failed.'));
        }

        $decoded = $interactionResponse->json();

        if (! is_array($decoded)) {
            Log::warning('Gemini call quality analysis returned non-array JSON.', [
                'body' => $interactionResponse->body(),
            ]);

            throw new TranscriptionFailedException('Gemini returned invalid call quality analysis output.');
        }

        $text = $this->extractInteractionText($decoded);

        if ($text === null) {
            Log::warning('Gemini call quality analysis missing model text output.', [
                'body' => $interactionResponse->body(),
            ]);

            throw new TranscriptionFailedException('Gemini returned invalid call quality analysis output.');
        }

        return $this->parseCallQualityResponse($text);
    }

    /**
     * Score each diarized speaker turn with the same three quality dimensions as call-level analysis.
     *
     * @param  list<array{speaker?: string|null, start?: float|int|null, end?: float|int|null, text?: string}>  $segments
     * @return list<array{courtesy_score: int, rudeness_score: int, emotional_tone_score: int}>
     */
    public function analyzeSegmentQuality(array $segments): array
    {
        if ($segments === []) {
            return [];
        }

        $apiKey = config('whisper.gemini_api_key');

        if (! is_string($apiKey) || $apiKey === '') {
            throw new TranscriptionFailedException('Gemini API key is not configured.');
        }

        $batchSize = max(1, (int) config('whisper.segment_quality_batch_size', 25));
        $scoresByIndex = [];

        for ($offset = 0; $offset < count($segments); $offset += $batchSize) {
            $batch = array_slice($segments, $offset, $batchSize);
            $batchScores = $this->requestSegmentQualityBatch($apiKey, $batch, $offset);
            $scoresByIndex += $batchScores;
        }

        ksort($scoresByIndex);

        return array_values($scoresByIndex);
    }

    /**
     * @param  list<array{speaker?: string|null, start?: float|int|null, end?: float|int|null, text?: string}>  $segments
     * @return list<array{speaker?: string|null, start?: float|int|null, end?: float|int|null, text?: string, courtesy_score: int, rudeness_score: int, emotional_tone_score: int}>
     */
    public function applySegmentQualityScores(array $segments): array
    {
        if ($segments === []) {
            return [];
        }

        $scores = $this->analyzeSegmentQuality($segments);
        $enriched = [];

        foreach ($segments as $index => $segment) {
            $enriched[] = array_merge($segment, $scores[$index] ?? []);
        }

        return $enriched;
    }

    /**
     * @return array{text: string, segments: list<array{start: ?float, end: ?float, text: string, speaker: ?string}>, duration_seconds: ?int, model: ?string, whisper_language: ?string}
     */
    public function transcribe(string $absoluteAudioPath, string $language): array
    {
        if (! is_file($absoluteAudioPath)) {
            throw new TranscriptionFailedException("Audio file not found: {$absoluteAudioPath}");
        }

        $apiKey = config('whisper.gemini_api_key');

        if (! is_string($apiKey) || $apiKey === '') {
            throw new TranscriptionFailedException('Gemini API key is not configured.');
        }

        $mimeType = $this->detectMimeType($absoluteAudioPath);
        $languageCode = $this->mapLanguageCode($language);

        $uploadResponse = Http::timeout(120)
            ->withHeaders(['x-goog-api-key' => $apiKey])
            ->attach(
                'file',
                file_get_contents($absoluteAudioPath),
                basename($absoluteAudioPath),
                ['Content-Type' => $mimeType]
            )
            ->post(self::GEMINI_FILES_UPLOAD_URL);

        if ($uploadResponse->failed()) {
            throw new TranscriptionFailedException($this->resolveErrorMessage($uploadResponse->json(), $uploadResponse->body(), 'Gemini file upload failed.'));
        }

        $uploadPayload = $uploadResponse->json();

        if (! is_array($uploadPayload)) {
            throw new TranscriptionFailedException('Gemini file upload returned invalid JSON.');
        }

        $file = $this->waitForActiveFile($apiKey, $uploadPayload['file'] ?? null);

        $uri = isset($file['uri']) ? (string) $file['uri'] : null;
        $fileMimeType = isset($file['mimeType']) ? (string) $file['mimeType'] : (isset($file['mime_type']) ? (string) $file['mime_type'] : $mimeType);

        if ($uri === null || $uri === '') {
            throw new TranscriptionFailedException('Gemini file upload did not return a file URI.');
        }

        $interactionResponse = Http::timeout(120)
            ->withHeaders([
                'x-goog-api-key' => $apiKey,
                'Content-Type' => 'application/json',
            ])
            ->post(self::GEMINI_INTERACTIONS_URL, [
                'model' => $this->geminiTranscriptionModel(),
                'input' => [
                    [
                        'type' => 'text',
                        'text' => $this->buildTranscriptionDiarizationPrompt(),
                    ],
                    [
                        'type' => 'audio',
                        'uri' => $uri,
                        'mime_type' => $fileMimeType,
                    ],
                ],
                'generation_config' => [
                    'transcription_config' => [
                        'language_codes' => [$languageCode],
                        'mode' => [
                            'type' => 'verbatim',
                            'diarization_mode' => 'speaker',
                            'timestamp_granularities' => ['word'],
                        ],
                    ],
                ],
            ]);

        if ($interactionResponse->failed()) {
            throw new TranscriptionFailedException($this->resolveErrorMessage($interactionResponse->json(), $interactionResponse->body(), 'Gemini transcription request failed.'));
        }

        $decoded = $interactionResponse->json();

        if (! is_array($decoded)) {
            Log::warning('Gemini transcription returned non-array JSON.', [
                'body' => $interactionResponse->body(),
            ]);

            throw new TranscriptionFailedException('Gemini returned invalid transcription output.');
        }

        $content = $this->extractTranscriptionContent($decoded);

        if ($content === null) {
            Log::warning('Gemini transcription missing model text output.', [
                'body' => $interactionResponse->body(),
            ]);

            throw new TranscriptionFailedException('Gemini returned invalid transcription output.');
        }

        $words = $this->mapAnnotations($content['annotations']);
        $segments = $this->normalizeSpeakers($this->mergeSpeakerTurns($words));

        Log::debug('Gemini transcription diarization parsed.', [
            'raw_word_count' => count($words),
            'turn_count' => count($segments),
            'sample_turns' => array_slice($segments, 0, 5),
        ]);

        return [
            'text' => trim($content['text']),
            'segments' => $segments,
            'duration_seconds' => $this->durationFromSegments($segments),
            'model' => isset($decoded['model']) ? (string) $decoded['model'] : $this->geminiTranscriptionModel(),
            'whisper_language' => $languageCode,
        ];
    }

    /**
     * @return list<array{start: ?float, end: ?float, text: string, speaker: ?string}>
     */
    private function mapAnnotations(mixed $annotations): array
    {
        if (! is_array($annotations)) {
            return [];
        }

        $mapped = [];

        foreach ($annotations as $annotation) {
            if (! is_array($annotation)) {
                continue;
            }

            if (($annotation['type'] ?? null) !== 'word_info') {
                continue;
            }

            $mapped[] = [
                'start' => $this->parseOffset($annotation['start_offset'] ?? null),
                'end' => $this->parseOffset($annotation['end_offset'] ?? null),
                'text' => (string) ($annotation['text'] ?? ''),
                'speaker' => isset($annotation['speaker']) ? (string) $annotation['speaker'] : null,
            ];
        }

        return $mapped;
    }

    /**
     * Merge consecutive same-speaker word annotations into speaker-turn segments.
     *
     * @param  list<array{start: ?float, end: ?float, text: string, speaker: ?string}>  $words
     * @return list<array{start: ?float, end: ?float, text: string, speaker: ?string}>
     */
    private function mergeSpeakerTurns(array $words): array
    {
        if ($words === []) {
            return [];
        }

        usort(
            $words,
            static fn (array $left, array $right): int => ((float) ($left['start'] ?? 0)) <=> ((float) ($right['start'] ?? 0))
        );

        $turns = [];
        $current = null;

        foreach ($words as $word) {
            $text = trim($word['text']);

            if ($text === '') {
                continue;
            }

            if ($current !== null && $this->canMergeWordIntoTurn($current, $word, $words)) {
                $current['text'] .= (preg_match('/^[\p{P}\p{S}]/u', $text) === 1 ? '' : ' ').$text;
                $current['end'] = $word['end'] ?? $current['end'];

                continue;
            }

            if ($current !== null) {
                $turns[] = $current;
            }

            $current = [
                'start' => $word['start'],
                'end' => $word['end'],
                'text' => $text,
                'speaker' => $word['speaker'] ?? null,
            ];
        }

        if ($current !== null) {
            $turns[] = $current;
        }

        return $turns;
    }

    /**
     * @param  list<array{start: ?float, end: ?float, text: string, speaker: ?string}>  $allWords
     */
    private function canMergeWordIntoTurn(array $turn, array $word, array $allWords): bool
    {
        if (($turn['speaker'] ?? null) !== ($word['speaker'] ?? null)) {
            return false;
        }

        $turnSpeaker = $turn['speaker'];
        $turnStart = (float) ($turn['start'] ?? 0);
        $wordEnd = (float) ($word['end'] ?? 0);

        foreach ($allWords as $other) {
            if (($other['speaker'] ?? null) === $turnSpeaker) {
                continue;
            }

            $otherStart = (float) ($other['start'] ?? 0);
            $otherEnd = (float) ($other['end'] ?? 0);

            if ($otherStart < $wordEnd && $otherEnd > $turnStart) {
                return false;
            }
        }

        return true;
    }

    /**
     * Map Gemini speaker IDs (spk:0 / spk_1) onto call-center roles.
     * Uses contextual operator phrases from the diarization prompt, with a
     * first-speaker fallback when cues are ambiguous.
     *
     * @param  list<array{start: ?float, end: ?float, text: string, speaker: ?string}>  $segments
     * @return list<array{start: ?float, end: ?float, text: string, speaker: ?string}>
     */
    private function normalizeSpeakers(array $segments): array
    {
        $speakerMap = $this->buildSpeakerRoleMap($segments);

        return array_map(static function (array $segment) use ($speakerMap): array {
            $raw = $segment['speaker'] ?? null;

            if ($raw === null || $raw === '') {
                return $segment;
            }

            $normalized = strtolower($raw);

            $segment['speaker'] = match (true) {
                $normalized === 'operator' => 'operator',
                in_array($normalized, ['musteri', 'customer'], true) => 'musteri',
                default => $speakerMap[$raw] ?? $raw,
            };

            return $segment;
        }, $segments);
    }

    /**
     * @param  list<array{start: ?float, end: ?float, text: string, speaker: ?string}>  $segments
     * @return array<string, string>
     */
    private function buildSpeakerRoleMap(array $segments): array
    {
        $textBySpeaker = [];

        foreach ($segments as $segment) {
            $raw = $segment['speaker'] ?? null;

            if ($raw === null || $raw === '') {
                continue;
            }

            $normalized = strtolower($raw);

            if (in_array($normalized, ['operator', 'musteri', 'customer'], true)) {
                return [];
            }

            $textBySpeaker[$raw] = ($textBySpeaker[$raw] ?? '').' '.mb_strtolower((string) ($segment['text'] ?? ''), 'UTF-8');
        }

        if ($textBySpeaker === []) {
            return [];
        }

        if (count($textBySpeaker) === 1) {
            $raw = array_key_first($textBySpeaker);

            return [$raw => 'operator'];
        }

        $operatorPatterns = [
            'buyurun',
            'xoş gəldiniz',
            'xoş gəlmisiniz',
            'necə kömək',
            'kömək edə',
            'zəng etdiyiniz',
            'şirkətimiz',
            'sizə necə',
            'dinləyirəm',
            'thank you for calling',
            'how can i help',
            'how may i help',
            'здравствуйте',
            'чем могу помочь',
            'слушаю вас',
        ];

        $scores = [];

        foreach ($textBySpeaker as $raw => $text) {
            $scores[$raw] = 0;

            foreach ($operatorPatterns as $pattern) {
                if (str_contains($text, $pattern)) {
                    $scores[$raw] += 10;
                }
            }
        }

        $firstSpeaker = $segments[0]['speaker'] ?? null;

        if ($firstSpeaker !== null && isset($scores[$firstSpeaker])) {
            $scores[$firstSpeaker] += 3;
        }

        arsort($scores);

        $operatorRaw = array_key_first($scores);
        $map = [];

        foreach (array_keys($scores) as $raw) {
            $map[$raw] = $raw === $operatorRaw ? 'operator' : 'musteri';
        }

        return $map;
    }

    /**
     * @param  array<string, mixed>  $interaction
     * @return array{text: string, annotations: mixed}|null
     */
    private function extractTranscriptionContent(array $interaction): ?array
    {
        $steps = $interaction['steps'] ?? null;

        if (! is_array($steps)) {
            return null;
        }

        for ($index = count($steps) - 1; $index >= 0; $index--) {
            $step = $steps[$index];

            if (! is_array($step)) {
                continue;
            }

            $contentParts = $step['content'] ?? null;

            if (! is_array($contentParts)) {
                continue;
            }

            foreach ($contentParts as $part) {
                if (! is_array($part) || ! array_key_exists('text', $part)) {
                    continue;
                }

                return [
                    'text' => (string) $part['text'],
                    'annotations' => $part['annotations'] ?? [],
                ];
            }
        }

        return null;
    }

    private function buildTranscriptionDiarizationPrompt(): string
    {
        return <<<'PROMPT'
Transcribe this call-center phone conversation verbatim with speaker diarization.

Identify exactly two roles:
- "operator" — the call center agent who answers professionally, greets callers (e.g. "Buyurun", "Salam, necə kömək edə bilərəm"), and offers assistance.
- "musteri" — the customer/caller who placed the call and states their need.

Use contextual cues (who answers first, professional greeting vs. stating a problem) to assign roles — not arbitrary speaker order.
Tag each word with the correct role label. Consecutive words from the same speaker should form natural speaking turns.
PROMPT;
    }

    /**
     * @param  list<array{start: ?float, end: ?float, text: string}>  $segments
     */
    private function durationFromSegments(array $segments): ?int
    {
        $ends = array_filter(array_map(
            fn (array $segment): ?float => $segment['end'],
            $segments
        ));

        if ($ends === []) {
            return null;
        }

        return (int) max($ends);
    }

    private function parseOffset(mixed $offset): ?float
    {
        if ($offset === null) {
            return null;
        }

        if (is_int($offset) || is_float($offset)) {
            return (float) $offset;
        }

        $value = trim((string) $offset);

        if ($value === '') {
            return null;
        }

        if (preg_match('/^([\d.]+)s$/', $value, $matches) === 1) {
            return (float) $matches[1];
        }

        if (preg_match('/^([\d.]+)ms$/', $value, $matches) === 1) {
            return ((float) $matches[1]) / 1000;
        }

        return is_numeric($value) ? (float) $value : null;
    }

    private function detectMimeType(string $absoluteAudioPath): string
    {
        return match (strtolower(pathinfo($absoluteAudioPath, PATHINFO_EXTENSION))) {
            'mp3' => 'audio/mpeg',
            'wav' => 'audio/wav',
            'm4a' => 'audio/mp4',
            'ogg' => 'audio/ogg',
            'webm' => 'audio/webm',
            default => 'application/octet-stream',
        };
    }

    private function mapLanguageCode(string $language): string
    {
        return match (strtolower($language)) {
            'az' => 'az-AZ',
            'ru' => 'ru-RU',
            'en' => 'en-US',
            default => 'az-AZ',
        };
    }

    /**
     * @return array<string, mixed>
     */
    private function waitForActiveFile(string $apiKey, mixed $file): array
    {
        if (! is_array($file)) {
            throw new TranscriptionFailedException('Gemini file upload did not return file metadata.');
        }

        $state = $file['state'] ?? null;

        if ($state === 'ACTIVE') {
            return $file;
        }

        $name = $file['name'] ?? null;

        if (! is_string($name) || $name === '') {
            throw new TranscriptionFailedException('Gemini file upload did not return a file name.');
        }

        for ($attempt = 0; $attempt < 30; $attempt++) {
            sleep(1);

            $response = Http::timeout(30)
                ->withHeaders(['x-goog-api-key' => $apiKey])
                ->get(self::GEMINI_FILES_BASE_URL.'/'.ltrim($name, '/'));

            if ($response->failed()) {
                throw new TranscriptionFailedException($this->resolveErrorMessage($response->json(), $response->body(), 'Gemini file status check failed.'));
            }

            $payload = $response->json();

            if (! is_array($payload)) {
                throw new TranscriptionFailedException('Gemini file status check returned invalid JSON.');
            }

            $currentFile = $payload['file'] ?? $payload;
            $currentState = is_array($currentFile) ? ($currentFile['state'] ?? null) : null;

            if ($currentState === 'ACTIVE' && is_array($currentFile)) {
                return $currentFile;
            }

            if ($currentState === 'FAILED') {
                $message = is_array($currentFile) && isset($currentFile['error']['message'])
                    ? (string) $currentFile['error']['message']
                    : 'Gemini file processing failed.';

                throw new TranscriptionFailedException($message);
            }
        }

        throw new TranscriptionFailedException('Gemini file did not become active in time.');
    }

    /**
     * POST to Gemini interactions with retry/backoff for transient overload.
     *
     * @param  array<string, mixed>  $payload
     */
    private function postGeminiInteraction(string $apiKey, array $payload, string $operation): Response
    {
        $response = null;

        for ($attempt = 1; $attempt <= self::GEMINI_INTERACTION_MAX_ATTEMPTS; $attempt++) {
            $response = Http::timeout(120)
                ->withHeaders([
                    'x-goog-api-key' => $apiKey,
                    'Content-Type' => 'application/json',
                ])
                ->post(self::GEMINI_INTERACTIONS_URL, $payload);

            if ($attempt === self::GEMINI_INTERACTION_MAX_ATTEMPTS || ! $this->shouldRetryGeminiInteraction($response)) {
                return $response;
            }

            $waitSeconds = self::GEMINI_INTERACTION_BACKOFF_SECONDS[$attempt - 1] ?? 10;

            Log::debug('Retrying Gemini request after transient overload.', [
                'operation' => $operation,
                'attempt' => $attempt,
                'wait_seconds' => $waitSeconds,
                'status' => $response->status(),
            ]);

            Sleep::for($waitSeconds)->seconds();
        }

        return $response;
    }

    private function shouldRetryGeminiInteraction(Response $response): bool
    {
        if (in_array($response->status(), [429, 503], true)) {
            return true;
        }

        if ($response->successful()) {
            return false;
        }

        $haystack = mb_strtolower(
            $this->resolveErrorMessage($response->json(), $response->body(), '').' '.$response->body(),
            'UTF-8'
        );

        return str_contains($haystack, 'high demand')
            || str_contains($haystack, 'try again later');
    }

    private function resolveErrorMessage(mixed $payload, string $body, string $fallback): string
    {
        if (is_array($payload)) {
            if (isset($payload['error']) && is_array($payload['error']) && isset($payload['error']['message'])) {
                return (string) $payload['error']['message'];
            }

            if (isset($payload['error']) && is_string($payload['error'])) {
                return $payload['error'];
            }

            if (isset($payload['message']) && is_string($payload['message'])) {
                return (string) $payload['message'];
            }
        }

        $trimmed = trim($body);

        return $trimmed !== '' ? $trimmed : $fallback;
    }

    private function geminiModel(): string
    {
        return (string) config('whisper.gemini_model', 'gemini-3.6-flash');
    }

    private function geminiTranscriptionModel(): string
    {
        return (string) config('whisper.gemini_transcription_model', 'gemini-3.5-transcribe');
    }

    private function buildCallQualityPrompt(string $transcript): string
    {
        return <<<PROMPT
        Analyze the following customer-service call transcript. Base your judgement strictly on wording, phrasing, and word choice in the transcript.

        Return ONLY raw JSON (no markdown fences, no explanation) with exactly these fields:
        - courtesy_score (0-100 integer): higher = more polite and professional
        - rudeness_score (0-100 integer): higher = more rude/disrespectful/hostile
        - emotional_tone_score (0-100 integer): higher = more agitated/negative emotional tone, lower = calmer/more positive
        - inappropriate_expressions (array): every inappropriate, offensive, vulgar, or rude expression used by either party (operator or musteri) in the transcript — including profanity, insults, slurs, sarcastic put-downs, and context-dependent rudeness (e.g. calling something or someone "zibil", "axmaq", etc.), not just explicit swear words. Also include contemptuous or dismissive idiomatic expressions the customer or operator uses about the company, product, or service (not just insults directed at a person) — for example, Azerbaijani idioms like 'zibilə vurmaq' (to waste/throw away, used dismissively about a service), calling something 'zibil' (garbage/worthless), or similar expressions implying something is worthless or a scam. Use judgment: routine factual complaints ('the balance is being deducted incorrectly') are NOT inappropriate; contemptuous language about the company/service IS inappropriate. For each one, return an object with:
          - "speaker": "operator" or "musteri" (whoever said it)
          - "text": the exact word or phrase as it appears in the transcript
          If none are found, return an empty array — do not invent expressions not present in the transcript.

        Transcript:
        {$transcript}
        PROMPT;
    }

    /**
     * @return array{courtesy_score: int, rudeness_score: int, emotional_tone_score: int, inappropriate_expressions: list<array{speaker?: string, text?: string}>|null}
     */
    private function parseCallQualityResponse(string $rawText): array
    {
        $decoded = json_decode($this->extractJsonObject($rawText), true);

        if (! is_array($decoded)) {
            throw new TranscriptionFailedException('Gemini call quality analysis returned invalid JSON.');
        }

        $scores = [];

        foreach (['courtesy_score', 'rudeness_score', 'emotional_tone_score'] as $key) {
            if (! array_key_exists($key, $decoded) || ! is_numeric($decoded[$key])) {
                throw new TranscriptionFailedException("Gemini call quality analysis returned invalid {$key}.");
            }

            $value = (int) $decoded[$key];

            if ($value < 0 || $value > 100) {
                throw new TranscriptionFailedException("Gemini call quality analysis returned out-of-range {$key}.");
            }

            $scores[$key] = $value;
        }

        $scores['inappropriate_expressions'] = $this->parseInappropriateExpressions(
            $decoded['inappropriate_expressions'] ?? null
        );

        return $scores;
    }

    /**
     * @return list<array{speaker?: string, text?: string}>|null
     */
    private function parseInappropriateExpressions(mixed $value): ?array
    {
        if ($value === null) {
            return null;
        }

        if (! is_array($value)) {
            return null;
        }

        $expressions = [];

        foreach ($value as $item) {
            if (! is_array($item)) {
                continue;
            }

            $expression = [];

            if (isset($item['speaker']) && is_string($item['speaker']) && $item['speaker'] !== '') {
                $expression['speaker'] = $item['speaker'];
            }

            $text = $item['text'] ?? $item['expression'] ?? $item['phrase'] ?? null;

            if (is_string($text) && $text !== '') {
                $expression['text'] = $text;
            }

            if ($expression !== []) {
                $expressions[] = $expression;
            }
        }

        return $expressions;
    }

    /**
     * @param  list<array{speaker?: string|null, start?: float|int|null, end?: float|int|null, text?: string}>  $segments
     * @return array<int, array{courtesy_score: int, rudeness_score: int, emotional_tone_score: int}>
     */
    private function requestSegmentQualityBatch(string $apiKey, array $segments, int $baseIndex): array
    {
        $interactionResponse = $this->postGeminiInteraction($apiKey, [
            'model' => $this->geminiModel(),
            'input' => [
                [
                    'type' => 'text',
                    'text' => $this->buildSegmentQualityPrompt($segments, $baseIndex),
                ],
            ],
            'response_format' => [
                'type' => 'text',
                'mime_type' => 'application/json',
                'schema' => [
                    'type' => 'array',
                    'items' => [
                        'type' => 'object',
                        'properties' => [
                            'segment_index' => ['type' => 'integer'],
                            'courtesy_score' => ['type' => 'integer', 'minimum' => 0, 'maximum' => 100],
                            'rudeness_score' => ['type' => 'integer', 'minimum' => 0, 'maximum' => 100],
                            'emotional_tone_score' => ['type' => 'integer', 'minimum' => 0, 'maximum' => 100],
                        ],
                        'required' => [
                            'segment_index',
                            'courtesy_score',
                            'rudeness_score',
                            'emotional_tone_score',
                        ],
                    ],
                ],
            ],
        ], 'segment quality analysis');

        if ($interactionResponse->failed()) {
            throw new TranscriptionFailedException($this->resolveErrorMessage($interactionResponse->json(), $interactionResponse->body(), 'Gemini segment quality analysis request failed.'));
        }

        $decoded = $interactionResponse->json();

        if (! is_array($decoded)) {
            Log::warning('Gemini segment quality analysis returned non-array JSON.', [
                'body' => $interactionResponse->body(),
            ]);

            throw new TranscriptionFailedException('Gemini returned invalid segment quality analysis output.');
        }

        $text = $this->extractInteractionText($decoded);

        if ($text === null) {
            Log::warning('Gemini segment quality analysis missing model text output.', [
                'body' => $interactionResponse->body(),
            ]);

            throw new TranscriptionFailedException('Gemini returned invalid segment quality analysis output.');
        }

        Log::debug('Gemini segment quality raw response.', [
            'base_index' => $baseIndex,
            'segment_count' => count($segments),
            'raw_text' => $text,
        ]);

        return $this->parseSegmentQualityBatchResponse($text, $baseIndex, count($segments));
    }

    /**
     * @param  list<array{speaker?: string|null, start?: float|int|null, end?: float|int|null, text?: string}>  $segments
     */
    private function buildSegmentQualityPrompt(array $segments, int $baseIndex): string
    {
        $lines = [];

        foreach ($segments as $localIndex => $segment) {
            $globalIndex = $baseIndex + $localIndex;
            $speaker = $segment['speaker'] ?? 'unknown';
            $start = $segment['start'] ?? '?';
            $end = $segment['end'] ?? '?';
            $text = $segment['text'] ?? '';
            $lines[] = "[{$globalIndex}] speaker={$speaker} ({$start}s-{$end}s): {$text}";
        }

        $segmentBlock = implode("\n", $lines);

        return <<<PROMPT
For each of the following speaker segments, rate courtesy_score, rudeness_score, and emotional_tone_score (0-100 integers) based on that segment's wording and tone in context. Higher courtesy is more polite. Higher rudeness is more rude. Higher emotional tone is more agitated or negative; lower emotional tone is calmer or positive.

Return ONLY raw JSON (no markdown fences, no explanation) as an array with one object per segment, in the same order as given. Each object must include: segment_index, courtesy_score, rudeness_score, emotional_tone_score.

Segments:
{$segmentBlock}
PROMPT;
    }

    /**
     * @return array<int, array{courtesy_score: int, rudeness_score: int, emotional_tone_score: int}>
     */
    private function parseSegmentQualityBatchResponse(string $rawText, int $baseIndex, int $expectedCount): array
    {
        $decoded = json_decode($this->extractJsonArray($rawText), true);

        if (! is_array($decoded)) {
            throw new TranscriptionFailedException('Gemini segment quality analysis returned invalid JSON.');
        }

        if (count($decoded) !== $expectedCount) {
            throw new TranscriptionFailedException(
                'Gemini segment quality analysis returned '.count($decoded)." scores, expected {$expectedCount}."
            );
        }

        $scoreKeys = ['courtesy_score', 'rudeness_score', 'emotional_tone_score'];
        $results = [];

        foreach ($decoded as $item) {
            if (! is_array($item)) {
                throw new TranscriptionFailedException('Gemini segment quality analysis returned invalid segment object.');
            }

            if (! array_key_exists('segment_index', $item) || ! is_numeric($item['segment_index'])) {
                throw new TranscriptionFailedException('Gemini segment quality analysis returned invalid segment_index.');
            }

            $segmentIndex = (int) $item['segment_index'];

            if ($segmentIndex < $baseIndex || $segmentIndex >= $baseIndex + $expectedCount) {
                throw new TranscriptionFailedException(
                    "Gemini segment quality analysis returned out-of-range segment_index {$segmentIndex}."
                );
            }

            $scores = [];

            foreach ($scoreKeys as $key) {
                if (! array_key_exists($key, $item) || ! is_numeric($item[$key])) {
                    throw new TranscriptionFailedException("Gemini segment quality analysis returned invalid {$key} for segment {$segmentIndex}.");
                }

                $value = (int) $item[$key];

                if ($value < 0 || $value > 100) {
                    throw new TranscriptionFailedException("Gemini segment quality analysis returned out-of-range {$key} for segment {$segmentIndex}.");
                }

                $scores[$key] = $value;
            }

            $results[$segmentIndex] = $scores;
        }

        for ($position = 0; $position < $expectedCount; $position++) {
            $expectedIndex = $baseIndex + $position;

            if (! array_key_exists($expectedIndex, $results)) {
                throw new TranscriptionFailedException(
                    "Gemini segment quality analysis missing scores for segment_index {$expectedIndex}."
                );
            }
        }

        return $results;
    }

    /**
     * @param  array<string, mixed>  $interaction
     */
    private function extractInteractionText(array $interaction): ?string
    {
        $steps = $interaction['steps'] ?? null;

        if (! is_array($steps)) {
            return null;
        }

        for ($index = count($steps) - 1; $index >= 0; $index--) {
            $step = $steps[$index];

            if (! is_array($step)) {
                continue;
            }

            $content = $step['content'] ?? null;

            if (! is_array($content)) {
                continue;
            }

            foreach ($content as $part) {
                if (! is_array($part) || ! array_key_exists('text', $part)) {
                    continue;
                }

                $text = trim((string) $part['text']);

                if ($text !== '') {
                    return $text;
                }
            }
        }

        return null;
    }

    private function extractJsonObject(string $text): string
    {
        $text = $this->stripMarkdownFences($text);

        $decoded = json_decode($text, true);

        if (is_array($decoded)) {
            return $text;
        }

        $start = strpos($text, '{');
        $end = strrpos($text, '}');

        if ($start !== false && $end !== false && $end > $start) {
            return substr($text, $start, $end - $start + 1);
        }

        return $text;
    }

    private function extractJsonArray(string $text): string
    {
        $text = $this->stripMarkdownFences($text);

        $decoded = json_decode($text, true);

        if (is_array($decoded)) {
            return $text;
        }

        $start = strpos($text, '[');
        $end = strrpos($text, ']');

        if ($start !== false && $end !== false && $end > $start) {
            return substr($text, $start, $end - $start + 1);
        }

        return $text;
    }

    private function stripMarkdownFences(string $text): string
    {
        $text = trim($text);

        if (preg_match('/^```(?:json)?\s*(.*?)\s*```$/is', $text, $matches) === 1) {
            return trim($matches[1]);
        }

        $text = preg_replace('/^```(?:json)?\s*/i', '', $text) ?? $text;
        $text = preg_replace('/\s*```$/', '', $text) ?? $text;

        return trim($text);
    }
}
