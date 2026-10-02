<?php

namespace App\Jobs;

use App\Models\Call;
use App\Models\CallAnalysis;
use App\Services\AiProviderResolver;
use App\Services\CallScoringService;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\DB;

class AnalyzeCallJob implements ShouldQueue
{
    use Queueable;

    public int $tries = 2;

    public int $timeout = 120;

    /**
     * @var list<int>
     */
    public array $backoff = [60, 180];

    public function __construct(public Call $call)
    {
        $this->timeout = max(180, (int) config('whisper.timeout', 120) * 2);
    }

    public function handle(CallScoringService $scoring, AiProviderResolver $providers): void
    {
        $call = $this->call->fresh(['analysis']);

        if (! $call) {
            return;
        }

        $transcript = trim((string) ($call->analysis?->transcript ?? ''));

        if ($transcript === '') {
            return;
        }

        /** @var list<array{speaker?: string|null, start?: float|int|null, end?: float|int|null, text?: string}> $segments */
        $segments = $call->analysis?->transcript_segments ?? [];

        $provider = $providers->analysis();
        $interruptionCounts = $scoring->calculateInterruptionCounts($segments);
        $qualityScores = $provider->analyze($transcript, $segments);
        $inappropriateCounts = $scoring->resolveInappropriateWordCounts(
            $qualityScores['inappropriate_expressions'] ?? null,
            $transcript,
            $call->language,
        );

        $totalScore = $scoring->calculateOverall(
            $scoring->overallInputs(
                politeness: $qualityScores['courtesy_score'],
                rudeness: $qualityScores['rudeness_score'],
                emotionalTone: $qualityScores['emotional_tone_score'],
                operatorInterruptions: $interruptionCounts['operator_interruption_count'],
                customerInterruptions: $interruptionCounts['customer_interruption_count'],
                inappropriateCounts: $inappropriateCounts,
            ),
            $scoring->overallWeights(),
            $scoring->gamma(),
        );

        DB::transaction(function () use ($call, $provider, $qualityScores, $interruptionCounts, $inappropriateCounts, $totalScore): void {
            CallAnalysis::query()->updateOrCreate(
                ['call_id' => $call->id],
                [
                    'courtesy_score' => $qualityScores['courtesy_score'],
                    'rudeness_score' => $qualityScores['rudeness_score'],
                    'emotional_tone_score' => $qualityScores['emotional_tone_score'],
                    'interruption_count' => $interruptionCounts['interruption_count'],
                    'operator_interruption_count' => $interruptionCounts['operator_interruption_count'],
                    'customer_interruption_count' => $interruptionCounts['customer_interruption_count'],
                    'inappropriate_words_count' => $inappropriateCounts['inappropriate_words_count'],
                    'operator_inappropriate_words_count' => $inappropriateCounts['operator_inappropriate_words_count'],
                    'customer_inappropriate_words_count' => $inappropriateCounts['customer_inappropriate_words_count'],
                    'inappropriate_expressions' => $qualityScores['inappropriate_expressions'] ?? null,
                    'total_score' => $totalScore,
                    'analyzed_at' => now(),
                ]
            );

            $call->update([
                'analysis_provider' => $provider->name(),
                'analysis_model' => $provider->model(),
            ]);
        });

        // Disabled: segment-level Gemini scoring not needed for now, re-enable if needed
        // if ($segments !== []) {
        //     try {
        //         $enrichedSegments = $whisper->applySegmentQualityScores($segments);
        //
        //         CallAnalysis::query()
        //             ->where('call_id', $call->id)
        //             ->update(['transcript_segments' => $enrichedSegments]);
        //
        //         Log::info('Segment quality scoring saved.', [
        //             'call_id' => $call->id,
        //             'segment_count' => count($enrichedSegments),
        //         ]);
        //     } catch (Throwable $exception) {
        //         Log::warning('Segment quality scoring failed; call-level analysis was saved.', [
        //             'call_id' => $call->id,
        //             'message' => $exception->getMessage(),
        //             'exception' => $exception::class,
        //         ]);
        //     }
        // }
    }
}
