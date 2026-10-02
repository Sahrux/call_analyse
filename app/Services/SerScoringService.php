<?php

namespace App\Services;

use App\Models\Call;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use RuntimeException;
use Throwable;

class SerScoringService
{
    public const MIN_SEGMENT_DURATION_SECONDS = 0.5;

    public function __construct(private AudioClipExtractionService $audioClips) {}

    /**
     * Score each transcript segment via the local SER inference service.
     *
     * Returns results in the same order as the call's transcript_segments.
     * Failed or skipped segments are represented as null (not an exception).
     *
     * @return list<array{
     *     courtesy_score: float,
     *     rudeness_score: float,
     *     emotional_tone_score: float,
     *     model_version: string
     * }|null>
     */
    public function scoreSegments(Call $call): array
    {
        $call->loadMissing('analysis');

        /** @var list<array<string, mixed>> $segments */
        $segments = $call->analysis?->transcript_segments ?? [];

        if ($segments === []) {
            Log::warning('SER segment scoring skipped: call has no transcript segments.', [
                'call_id' => $call->id,
            ]);

            return [];
        }

        $results = [];

        foreach ($segments as $index => $segment) {
            $results[] = $this->scoreSingleSegment($call, $segment, $index);
        }

        return $results;
    }

    /**
     * Mean of each score across successfully scored segments. Null when none succeeded.
     *
     * @param  list<array{
     *     courtesy_score: float,
     *     rudeness_score: float,
     *     emotional_tone_score: float,
     *     model_version: string
     * }|null>  $segmentResults
     * @return array{
     *     courtesy_score: float,
     *     rudeness_score: float,
     *     emotional_tone_score: float,
     *     model_version: string
     * }|null
     */
    public function aggregateSegmentScores(array $segmentResults): ?array
    {
        $scored = array_values(array_filter(
            $segmentResults,
            static fn (?array $result): bool => $result !== null,
        ));

        if ($scored === []) {
            return null;
        }

        $count = count($scored);
        $modelVersion = $scored[0]['model_version'];

        return [
            'courtesy_score' => array_sum(array_column($scored, 'courtesy_score')) / $count,
            'rudeness_score' => array_sum(array_column($scored, 'rudeness_score')) / $count,
            'emotional_tone_score' => array_sum(array_column($scored, 'emotional_tone_score')) / $count,
            'model_version' => $modelVersion,
        ];
    }

    /**
     * Score a call's full audio via the local SER inference service.
     *
     * @deprecated Prefer scoreSegments() — whole-call audio is truncated to ~8s by the model.
     *
     * @return array{
     *     courtesy_score: float,
     *     rudeness_score: float,
     *     emotional_tone_score: float,
     *     model_version: string
     * }|null Null when the service is unreachable (caller should skip).
     */
    public function scoreCall(Call $call): ?array
    {
        if ($call->audio_path === null) {
            Log::warning('SER scoring skipped: call has no audio path.', [
                'call_id' => $call->id,
            ]);

            return null;
        }

        $disk = Storage::disk('public');

        if (! $disk->exists($call->audio_path)) {
            Log::warning('SER scoring skipped: audio file missing.', [
                'call_id' => $call->id,
                'audio_path' => $call->audio_path,
            ]);

            return null;
        }

        $absolutePath = $disk->path($call->audio_path);
        $filename = $call->audio_original_name ?: basename($call->audio_path);

        return $this->predictAudioFile($absolutePath, $filename, $call->id);
    }

    public function isHealthy(): bool
    {
        $serviceUrl = rtrim((string) config('ser.service_url'), '/');

        try {
            $response = Http::connectTimeout((int) config('ser.connect_timeout', 3))
                ->timeout(5)
                ->get($serviceUrl.'/health');
        } catch (Throwable) {
            return false;
        }

        return $response->successful()
            && ($response->json('status') === 'ok');
    }

    /**
     * @param  array<string, mixed>  $payload
     * @return array{
     *     courtesy_score: float,
     *     rudeness_score: float,
     *     emotional_tone_score: float,
     *     model_version: string
     * }
     */
    public function normalizePayload(array $payload): array
    {
        $modelVersion = $payload['model_version'] ?? null;

        if (! is_string($modelVersion) || $modelVersion === '') {
            throw new RuntimeException('SER response missing model_version.');
        }

        return [
            'courtesy_score' => $this->floatScore($payload, 'courtesy_score'),
            'rudeness_score' => $this->floatScore($payload, 'rudeness_score'),
            'emotional_tone_score' => $this->floatScore($payload, 'emotional_tone_score'),
            'model_version' => $modelVersion,
        ];
    }

    /**
     * @param  array<string, mixed>  $segment
     * @return array{
     *     courtesy_score: float,
     *     rudeness_score: float,
     *     emotional_tone_score: float,
     *     model_version: string
     * }|null
     */
    private function scoreSingleSegment(Call $call, array $segment, int $index): ?array
    {
        $start = isset($segment['start']) ? (float) $segment['start'] : null;
        $end = isset($segment['end']) ? (float) $segment['end'] : null;

        if ($start === null || $end === null || $end <= $start) {
            Log::warning('SER segment scoring skipped: invalid segment timing.', [
                'call_id' => $call->id,
                'segment_index' => $index,
            ]);

            return null;
        }

        if (($end - $start) < self::MIN_SEGMENT_DURATION_SECONDS) {
            Log::warning('SER segment scoring skipped: segment shorter than minimum duration.', [
                'call_id' => $call->id,
                'segment_index' => $index,
                'duration' => $end - $start,
            ]);

            return null;
        }

        $clipPath = null;

        try {
            $clipPath = $this->audioClips->extractSegmentClip($call, $segment);

            if ($clipPath === null) {
                return null;
            }

            $filename = basename($clipPath);

            return $this->predictAudioFile($clipPath, $filename, $call->id, $index);
        } catch (Throwable $exception) {
            Log::warning('SER segment scoring failed unexpectedly.', [
                'call_id' => $call->id,
                'segment_index' => $index,
                'message' => $exception->getMessage(),
                'exception' => $exception::class,
            ]);

            return null;
        } finally {
            if (is_string($clipPath) && is_file($clipPath)) {
                @unlink($clipPath);
            }
        }
    }

    /**
     * @return array{
     *     courtesy_score: float,
     *     rudeness_score: float,
     *     emotional_tone_score: float,
     *     model_version: string
     * }|null
     */
    private function predictAudioFile(
        string $absolutePath,
        string $filename,
        ?int $callId = null,
        ?int $segmentIndex = null,
    ): ?array {
        $contents = @file_get_contents($absolutePath);

        if ($contents === false) {
            Log::warning('SER scoring skipped: unable to read audio file.', [
                'call_id' => $callId,
                'segment_index' => $segmentIndex,
                'audio_path' => $absolutePath,
            ]);

            return null;
        }

        $serviceUrl = rtrim((string) config('ser.service_url'), '/');
        $timeout = (int) config('ser.timeout', 30);
        $connectTimeout = (int) config('ser.connect_timeout', 3);

        try {
            $response = Http::connectTimeout($connectTimeout)
                ->timeout($timeout)
                ->attach('file', $contents, $filename)
                ->post($serviceUrl.'/predict');
        } catch (ConnectionException $exception) {
            Log::warning('SER scoring skipped: inference service unreachable.', [
                'call_id' => $callId,
                'segment_index' => $segmentIndex,
                'service_url' => $serviceUrl,
                'message' => $exception->getMessage(),
            ]);

            return null;
        } catch (Throwable $exception) {
            Log::warning('SER scoring skipped: unexpected HTTP client failure.', [
                'call_id' => $callId,
                'segment_index' => $segmentIndex,
                'service_url' => $serviceUrl,
                'message' => $exception->getMessage(),
                'exception' => $exception::class,
            ]);

            return null;
        }

        if (! $response->successful()) {
            Log::warning('SER scoring skipped: inference service returned an error.', [
                'call_id' => $callId,
                'segment_index' => $segmentIndex,
                'status' => $response->status(),
                'body' => $response->body(),
            ]);

            return null;
        }

        /** @var array<string, mixed> $payload */
        $payload = $response->json() ?? [];

        try {
            return $this->normalizePayload($payload);
        } catch (RuntimeException $exception) {
            Log::warning('SER scoring skipped: invalid inference payload.', [
                'call_id' => $callId,
                'segment_index' => $segmentIndex,
                'message' => $exception->getMessage(),
            ]);

            return null;
        }
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    private function floatScore(array $payload, string $key): float
    {
        if (! array_key_exists($key, $payload) || ! is_numeric($payload[$key])) {
            throw new RuntimeException("SER response missing numeric {$key}.");
        }

        return (float) $payload[$key];
    }
}
