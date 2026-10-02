<?php

namespace App\Services\Analysis;

use App\Contracts\AnalysisProvider;
use App\Exceptions\TranscriptionFailedException;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Http;

class OpenAiAnalysisProvider implements AnalysisProvider
{
    public function name(): string
    {
        return 'openai';
    }

    public function model(): string
    {
        return (string) config('services.openai.analysis_model', 'gpt-6-luna');
    }

    public function analyze(string $transcript, array $segments): array
    {
        $apiKey = config('services.openai.key');

        if (! is_string($apiKey) || $apiKey === '') {
            throw new TranscriptionFailedException('OPENAI_API_KEY təyin edilməyib');
        }

        $transcriptText = $this->transcriptText($segments);

        $response = Http::withToken($apiKey)
            ->connectTimeout(10)
            ->timeout(180)
            ->acceptJson()
            ->post('https://api.openai.com/v1/responses', [
                'model' => $this->model(),
                'input' => [
                    [
                        'role' => 'developer',
                        'content' => [
                            ['type' => 'input_text', 'text' => $this->developerPrompt()],
                        ],
                    ],
                    [
                        'role' => 'user',
                        'content' => [
                            ['type' => 'input_text', 'text' => $transcriptText],
                        ],
                    ],
                ],
                'reasoning' => ['effort' => 'medium'],
                'text' => ['format' => ['type' => 'text']],
                'store' => false,
                'stream' => false,
            ]);

        if ($response->failed()) {
            throw new TranscriptionFailedException($this->errorMessage($response, 'OpenAI analysis request failed.'));
        }

        $payload = $response->json();

        if (! is_array($payload)) {
            throw new TranscriptionFailedException('OpenAI returned invalid analysis output.');
        }

        return $this->parseAnalysisText($this->outputText($payload));
    }

    /**
     * Emotional tone follows the Gemini convention already stored by the app:
     * a higher score means more agitation or negativity, so the OpenAI value
     * is kept as returned and is not inverted.
     *
     * @return array{courtesy_score: int, rudeness_score: int, emotional_tone_score: int, inappropriate_expressions: list<array{speaker: string, text: string}>}
     */
    private function parseAnalysisText(string $rawText): array
    {
        $decoded = json_decode($this->stripFences($rawText), true);

        if (! is_array($decoded) || array_is_list($decoded)) {
            throw new TranscriptionFailedException('OpenAI analysis returned invalid JSON.');
        }

        $scores = [];

        foreach (['courtesy_score', 'rudeness_score', 'emotional_tone_score'] as $key) {
            if (! array_key_exists($key, $decoded) || ! is_numeric($decoded[$key])) {
                throw new TranscriptionFailedException("OpenAI analysis returned invalid {$key}.");
            }

            $scores[$key] = max(0, min(100, (int) $decoded[$key]));
        }

        if (! array_key_exists('inappropriate_expressions', $decoded) || ! is_array($decoded['inappropriate_expressions'])) {
            throw new TranscriptionFailedException('OpenAI analysis returned invalid inappropriate_expressions.');
        }

        $expressions = [];

        foreach ($decoded['inappropriate_expressions'] as $item) {
            if (! is_array($item)) {
                throw new TranscriptionFailedException('OpenAI analysis returned invalid inappropriate_expressions.');
            }

            $speaker = strtolower(trim((string) ($item['speaker'] ?? '')));
            $text = trim((string) ($item['text'] ?? ''));

            if (! in_array($speaker, ['operator', 'musteri'], true) || $text === '') {
                throw new TranscriptionFailedException('OpenAI analysis returned invalid inappropriate_expressions.');
            }

            $expressions[] = [
                'speaker' => $speaker,
                'text' => $text,
            ];
        }

        $scores['inappropriate_expressions'] = $expressions;

        return $scores;
    }

    /**
     * @param  list<array{speaker?: string|null, start?: float|int|null, end?: float|int|null, text?: string|null}>  $segments
     */
    private function transcriptText(array $segments): string
    {
        $ordered = $segments;

        usort(
            $ordered,
            static fn (array $left, array $right): int => ((float) ($left['start'] ?? 0)) <=> ((float) ($right['start'] ?? 0))
        );

        $lines = [];

        foreach ($ordered as $segment) {
            $text = trim((string) ($segment['text'] ?? ''));

            if ($text === '') {
                continue;
            }

            $lines[] = $this->role($segment['speaker'] ?? null).': '.$text;
        }

        if ($lines === []) {
            throw new TranscriptionFailedException('Transkript seqmentləri yoxdur.');
        }

        return implode("\n", $lines);
    }

    private function role(mixed $speaker): string
    {
        $normalized = strtolower(trim((string) $speaker));

        return match (true) {
            $normalized === 'operator' => 'operator',
            in_array($normalized, ['musteri', 'customer'], true) => 'musteri',
            preg_match('/^spk[:_]?0$/', $normalized) === 1 => 'operator',
            default => 'musteri',
        };
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    private function outputText(array $payload): string
    {
        $parts = [];
        $output = $payload['output'] ?? null;

        if (is_array($output)) {
            foreach ($output as $item) {
                if (! is_array($item) || ($item['type'] ?? null) !== 'message') {
                    continue;
                }

                $content = $item['content'] ?? null;

                if (! is_array($content)) {
                    continue;
                }

                foreach ($content as $part) {
                    if (! is_array($part) || ($part['type'] ?? null) !== 'output_text') {
                        continue;
                    }

                    $parts[] = (string) ($part['text'] ?? '');
                }
            }
        }

        $text = trim(implode('', $parts));

        if ($text === '') {
            throw new TranscriptionFailedException('OpenAI returned invalid analysis output.');
        }

        return $text;
    }

    private function developerPrompt(): string
    {
        $path = resource_path('prompts/openai_call_analysis.txt');

        if (! is_file($path)) {
            throw new TranscriptionFailedException('OpenAI analysis prompt file is missing.');
        }

        $prompt = file_get_contents($path);

        if (! is_string($prompt) || trim($prompt) === '') {
            throw new TranscriptionFailedException('OpenAI analysis prompt file is empty.');
        }

        return $prompt;
    }

    private function stripFences(string $text): string
    {
        $text = trim($text);

        if (preg_match('/^```(?:json)?\s*(.*?)\s*```$/is', $text, $matches) === 1) {
            return trim($matches[1]);
        }

        $text = preg_replace('/^```(?:json)?\s*/i', '', $text) ?? $text;
        $text = preg_replace('/\s*```$/', '', $text) ?? $text;

        return trim($text);
    }

    private function errorMessage(Response $response, string $fallback): string
    {
        $payload = $response->json();

        if (is_array($payload)) {
            if (isset($payload['error']) && is_array($payload['error']) && isset($payload['error']['message'])) {
                return (string) $payload['error']['message'];
            }

            if (isset($payload['message']) && is_string($payload['message']) && $payload['message'] !== '') {
                return $payload['message'];
            }
        }

        $body = trim($response->body());

        return $body !== '' ? $body : $fallback;
    }
}
