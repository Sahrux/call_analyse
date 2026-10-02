<?php

namespace App\Services;

use App\Models\Call;
use App\Models\CallAnalysis;
use Illuminate\Support\Collection;

class CallSegmentScoreBackfillService
{
    private const SCORE_KEYS = [
        'courtesy_score',
        'rudeness_score',
        'emotional_tone_score',
    ];

    /**
     * @return Collection<int, Call>
     */
    public function callsNeedingSegmentScores(?int $callId = null): Collection
    {
        $query = Call::query()
            ->with('analysis')
            ->where('status', Call::STATUS_COMPLETED)
            ->whereHas('analysis', function ($analysisQuery): void {
                $analysisQuery->whereNotNull('transcript_segments');
            });

        if ($callId !== null) {
            $query->whereKey($callId);
        }

        return $query->orderBy('id')->get()->filter(function (Call $call): bool {
            return $call->analysis instanceof CallAnalysis
                && $this->analysisNeedsSegmentScores($call->analysis);
        })->values();
    }

    public function analysisNeedsSegmentScores(CallAnalysis $analysis): bool
    {
        /** @var list<array<string, mixed>> $segments */
        $segments = $analysis->transcript_segments ?? [];

        if ($segments === []) {
            return false;
        }

        foreach ($segments as $segment) {
            if (! $this->segmentHasAiScores($segment)) {
                return true;
            }
        }

        return false;
    }

    /**
     * @param  array<string, mixed>  $segment
     */
    public function segmentHasAiScores(array $segment): bool
    {
        foreach (self::SCORE_KEYS as $key) {
            if (! array_key_exists($key, $segment) || ! is_numeric($segment[$key])) {
                return false;
            }
        }

        return true;
    }

    public function backfillCall(Call $call, WhisperTranscriptionService $whisper): int
    {
        $analysis = $call->analysis;

        if (! $analysis) {
            return 0;
        }

        /** @var list<array<string, mixed>> $segments */
        $segments = $analysis->transcript_segments ?? [];

        if ($segments === []) {
            return 0;
        }

        $enrichedSegments = $whisper->applySegmentQualityScores($segments);
        $analysis->update(['transcript_segments' => $enrichedSegments]);

        return count($enrichedSegments);
    }
}
