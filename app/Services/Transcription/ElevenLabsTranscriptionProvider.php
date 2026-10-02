<?php

namespace App\Services\Transcription;

use App\Contracts\TranscriptionProvider;
use App\Exceptions\TranscriptionFailedException;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Http;

class ElevenLabsTranscriptionProvider implements TranscriptionProvider
{
    public function name(): string
    {
        return 'elevenlabs';
    }

    public function model(): string
    {
        return (string) config('services.elevenlabs.stt_model', 'scribe_v1');
    }

    public function transcribe(string $absoluteAudioPath, string $language): array
    {
        if (! is_file($absoluteAudioPath)) {
            throw new TranscriptionFailedException("Audio file not found: {$absoluteAudioPath}");
        }

        $apiKey = config('services.elevenlabs.key');

        if (! is_string($apiKey) || $apiKey === '') {
            throw new TranscriptionFailedException('ELEVENLABS_API_KEY təyin edilməyib');
        }

        $contents = file_get_contents($absoluteAudioPath);

        if ($contents === false) {
            throw new TranscriptionFailedException("Audio file not found: {$absoluteAudioPath}");
        }

        $response = Http::withHeaders([
            'xi-api-key' => $apiKey,
        ])
            ->connectTimeout(10)
            ->timeout(300)
            ->attach(
                'file',
                $contents,
                basename($absoluteAudioPath),
                ['Content-Type' => $this->mimeType($absoluteAudioPath)]
            )
            ->post('https://api.elevenlabs.io/v1/speech-to-text', [
                'model_id' => $this->model(),
                'diarize' => 'true',
                'timestamps_granularity' => 'word',
                'tag_audio_events' => 'false',
            ]);

        if ($response->failed()) {
            throw new TranscriptionFailedException($this->errorMessage($response, 'ElevenLabs transcription request failed.'));
        }

        $payload = $response->json();

        if (! is_array($payload)) {
            throw new TranscriptionFailedException('ElevenLabs returned invalid transcription output.');
        }

        $segments = $this->segmentsFromWords($payload['words'] ?? null);
        $text = trim((string) ($payload['text'] ?? ''));

        if ($text === '') {
            $text = trim(implode(' ', array_map(
                static fn (array $segment): string => $segment['text'],
                $segments
            )));
        }

        return [
            'text' => $text,
            'segments' => $segments,
            'duration_seconds' => $this->durationFromSegments($segments),
            'model' => $this->model(),
            'whisper_language' => $this->mapLanguageCode(
                isset($payload['language_code']) ? (string) $payload['language_code'] : null
            ),
        ];
    }

    /**
     * @return list<array{start: ?float, end: ?float, text: string, speaker: ?string}>
     */
    private function segmentsFromWords(mixed $words): array
    {
        if (! is_array($words)) {
            return [];
        }

        $spoken = [];

        foreach ($words as $index => $word) {
            if (! is_array($word) || ($word['type'] ?? null) !== 'word') {
                continue;
            }

            $text = trim((string) ($word['text'] ?? ''));

            if ($text === '') {
                continue;
            }

            $spoken[] = [
                'index' => $index,
                'start' => $this->seconds($word['start'] ?? null),
                'end' => $this->seconds($word['end'] ?? null),
                'text' => $text,
                'speaker_id' => isset($word['speaker_id']) ? (string) $word['speaker_id'] : '',
            ];
        }

        if ($spoken === []) {
            return [];
        }

        usort(
            $spoken,
            static fn (array $left, array $right): int => (($left['start'] ?? 0.0) <=> ($right['start'] ?? 0.0))
                ?: ($left['index'] <=> $right['index'])
        );

        $firstSpeakerId = $spoken[0]['speaker_id'];
        $segments = [];
        $current = null;

        foreach ($spoken as $word) {
            $role = $word['speaker_id'] === $firstSpeakerId ? 'operator' : 'musteri';

            if ($current !== null && $current['speaker'] === $role && $current['speaker_id'] === $word['speaker_id']) {
                $current['text'] .= ' '.$word['text'];
                $current['end'] = $word['end'] ?? $current['end'];

                continue;
            }

            if ($current !== null) {
                unset($current['speaker_id']);
                $segments[] = $current;
            }

            $current = [
                'start' => $word['start'],
                'end' => $word['end'],
                'text' => $word['text'],
                'speaker' => $role,
                'speaker_id' => $word['speaker_id'],
            ];
        }

        if ($current !== null) {
            unset($current['speaker_id']);
            $segments[] = $current;
        }

        return $segments;
    }

    /**
     * @param  list<array{start: ?float, end: ?float, text: string, speaker: ?string}>  $segments
     */
    private function durationFromSegments(array $segments): ?int
    {
        $ends = array_filter(array_map(
            static fn (array $segment): ?float => $segment['end'],
            $segments
        ), static fn (?float $end): bool => $end !== null);

        if ($ends === []) {
            return null;
        }

        return (int) max($ends);
    }

    private function seconds(mixed $value): ?float
    {
        if (is_int($value) || is_float($value)) {
            return (float) $value;
        }

        if (is_string($value) && is_numeric($value)) {
            return (float) $value;
        }

        return null;
    }

    private function mimeType(string $absoluteAudioPath): string
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

    private function mapLanguageCode(?string $code): ?string
    {
        if ($code === null || trim($code) === '') {
            return null;
        }

        $normalized = strtolower(str_replace('_', '-', trim($code)));

        return match (true) {
            str_starts_with($normalized, 'az') => 'az-AZ',
            str_starts_with($normalized, 'ru') => 'ru-RU',
            str_starts_with($normalized, 'en') => 'en-US',
            default => $code,
        };
    }

    private function errorMessage(Response $response, string $fallback): string
    {
        $payload = $response->json();

        if (is_array($payload)) {
            $detail = $payload['detail'] ?? null;

            if (is_array($detail) && isset($detail['message']) && is_string($detail['message'])) {
                return $detail['message'];
            }

            if (is_string($detail) && $detail !== '') {
                return $detail;
            }

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
