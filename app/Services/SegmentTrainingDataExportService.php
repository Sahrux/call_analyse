<?php

namespace App\Services;

use App\Models\Call;
use Carbon\Carbon;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Storage;

class SegmentTrainingDataExportService
{
    private const SCORE_KEYS = [
        'courtesy_score',
        'rudeness_score',
        'emotional_tone_score',
    ];

    public function __construct(private AudioClipExtractionService $audioClips) {}

    /**
     * @param  (callable(string, float, float, string): bool)|null  $clipExtractor
     * @return array{calls_processed: int, clips_exported: int, clips_skipped: int, exported_audio_seconds: float}
     */
    public function export(
        ?Carbon $from,
        ?Carbon $to,
        string $outputDir,
        float $minDurationSeconds = 0.5,
        ?callable $clipExtractor = null,
    ): array {
        File::ensureDirectoryExists($outputDir);

        $manifestPath = $outputDir.DIRECTORY_SEPARATOR.'manifest.jsonl';
        File::put($manifestPath, '');

        $callsProcessed = 0;
        $clipsExported = 0;
        $clipsSkipped = 0;
        $exportedAudioSeconds = 0.0;

        $query = Call::query()
            ->with('analysis')
            ->where('status', Call::STATUS_COMPLETED)
            ->whereHas('analysis', function ($analysisQuery): void {
                $analysisQuery->whereNotNull('transcript_segments');
            });

        if ($from !== null) {
            $query->where('called_at', '>=', $from);
        }

        if ($to !== null) {
            $query->where('called_at', '<=', $to);
        }

        $query->orderBy('id')->chunkById(50, function ($calls) use (
            $outputDir,
            $manifestPath,
            $minDurationSeconds,
            $clipExtractor,
            &$callsProcessed,
            &$clipsExported,
            &$clipsSkipped,
            &$exportedAudioSeconds,
        ): void {
            foreach ($calls as $call) {
                if (! $call->audio_path || ! $call->analysis) {
                    continue;
                }

                $audioPath = Storage::disk('public')->path($call->audio_path);

                if (! is_file($audioPath)) {
                    continue;
                }

                /** @var list<array<string, mixed>> $segments */
                $segments = $call->analysis->transcript_segments ?? [];

                if ($segments === []) {
                    continue;
                }

                $callsProcessed++;

                foreach ($segments as $segmentIndex => $segment) {
                    if (! $this->segmentHasQualityScores($segment)) {
                        $clipsSkipped++;
                        \Log::debug('skip: no scores', ['speaker' => $segment['speaker'] ?? null, 'keys' => array_keys($segment)]);

                        continue;
                    }

                    $start = isset($segment['start']) ? (float) $segment['start'] : null;
                    $end = isset($segment['end']) ? (float) $segment['end'] : null;

                    if ($start === null || $end === null || $end <= $start) {
                        $clipsSkipped++;

                        continue;
                    }

                    $duration = $end - $start;

                    if ($duration < $minDurationSeconds) {
                        $clipsSkipped++;

                        continue;
                    }

                    $clipFilename = sprintf('%d_%d.wav', $call->id, $segmentIndex);
                    $clipPath = $outputDir.DIRECTORY_SEPARATOR.$clipFilename;

                    $extracted = $clipExtractor !== null
                        ? $clipExtractor($audioPath, $start, $end, $clipPath)
                        : $this->audioClips->extractClip($audioPath, $start, $end, $clipPath);

                    if (! $extracted) {
                        $clipsSkipped++;

                        continue;
                    }

                    $manifestLine = json_encode([
                        'clip_path' => $clipFilename,
                        'call_id' => $call->id,
                        'segment_index' => $segmentIndex,
                        'speaker' => $segment['speaker'] ?? null,
                        'duration_seconds' => round($duration, 3),
                        'courtesy_score' => (int) ($segment['courtesy_score'] ?? $segment['manual_courtesy_score']),
                        'rudeness_score' => (int) ($segment['rudeness_score'] ?? $segment['manual_rudeness_score']),
                        'emotional_tone_score' => (int) ($segment['emotional_tone_score'] ?? $segment['manual_emotional_tone_score']),
                        'manual_courtesy_score' => isset($segment['manual_courtesy_score']) && is_numeric($segment['manual_courtesy_score'])
                            ? (int) $segment['manual_courtesy_score']
                            : null,
                        'manual_rudeness_score' => isset($segment['manual_rudeness_score']) && is_numeric($segment['manual_rudeness_score'])
                            ? (int) $segment['manual_rudeness_score']
                            : null,
                        'manual_emotional_tone_score' => isset($segment['manual_emotional_tone_score']) && is_numeric($segment['manual_emotional_tone_score'])
                            ? (int) $segment['manual_emotional_tone_score']
                            : null,
                        'has_manual_review' => (bool) ($segment['manual_reviewed'] ?? false),
                    ], JSON_THROW_ON_ERROR);

                    File::append($manifestPath, $manifestLine.PHP_EOL);

                    $clipsExported++;
                    $exportedAudioSeconds += $duration;
                }
            }
        });

        return [
            'calls_processed' => $callsProcessed,
            'clips_exported' => $clipsExported,
            'clips_skipped' => $clipsSkipped,
            'exported_audio_seconds' => round($exportedAudioSeconds, 3),
        ];
    }

    /**
     * @param  array<string, mixed>  $segment
     */
    private function segmentHasQualityScores(array $segment): bool
    {
        foreach (self::SCORE_KEYS as $key) {
            $manualKey = 'manual_'.$key;
            $hasAutoScore = array_key_exists($key, $segment) && is_numeric($segment[$key]);
            $hasManualScore = array_key_exists($manualKey, $segment) && is_numeric($segment[$manualKey]);

            if (! $hasAutoScore && ! $hasManualScore) {
                return false;
            }
        }

        return true;
    }
}
