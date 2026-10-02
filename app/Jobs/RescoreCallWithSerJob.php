<?php

namespace App\Jobs;

use App\Models\Call;
use App\Models\SerScore;
use App\Services\SerScoringService;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Log;

class RescoreCallWithSerJob implements ShouldQueue
{
    use Queueable;

    public int $tries = 3;

    public int $timeout = 600;

    /**
     * @var list<int>
     */
    public array $backoff = [30, 60, 120];

    public function __construct(public Call $call) {}

    public function handle(SerScoringService $scoring): void
    {
        $call = $this->call->fresh(['analysis']);

        if (! $call) {
            return;
        }

        $segmentResults = $scoring->scoreSegments($call);
        $aggregate = $scoring->aggregateSegmentScores($segmentResults);

        if ($aggregate === null) {
            // Soft skip: missing audio, no segments, or SER service down — do not fail the whole backfill.
            Log::warning('RescoreCallWithSerJob skipped call (no segment scores returned).', [
                'call_id' => $call->id,
            ]);

            return;
        }

        $this->persistSegmentSerScores($call, $segmentResults, $aggregate['model_version']);

        SerScore::query()->create([
            'call_id' => $call->id,
            'model_version' => $aggregate['model_version'],
            'courtesy_score' => $aggregate['courtesy_score'],
            'rudeness_score' => $aggregate['rudeness_score'],
            'emotional_tone_score' => $aggregate['emotional_tone_score'],
            'scored_at' => now(),
        ]);
    }

    /**
     * @param  list<array{
     *     courtesy_score: float,
     *     rudeness_score: float,
     *     emotional_tone_score: float,
     *     model_version: string
     * }|null>  $segmentResults
     */
    private function persistSegmentSerScores(Call $call, array $segmentResults, string $fallbackModelVersion): void
    {
        $analysis = $call->analysis;

        if (! $analysis) {
            return;
        }

        /** @var list<array<string, mixed>> $segments */
        $segments = $analysis->transcript_segments ?? [];

        if ($segments === []) {
            return;
        }

        foreach ($segments as $index => $segment) {
            $result = $segmentResults[$index] ?? null;

            if ($result === null) {
                $segments[$index] = array_merge($segment, [
                    'ser_courtesy_score' => null,
                    'ser_rudeness_score' => null,
                    'ser_emotional_tone_score' => null,
                    'ser_model_version' => null,
                ]);

                continue;
            }

            $segments[$index] = array_merge($segment, [
                'ser_courtesy_score' => $result['courtesy_score'],
                'ser_rudeness_score' => $result['rudeness_score'],
                'ser_emotional_tone_score' => $result['emotional_tone_score'],
                'ser_model_version' => $result['model_version'] ?: $fallbackModelVersion,
            ]);
        }

        $analysis->update(['transcript_segments' => $segments]);
    }
}
