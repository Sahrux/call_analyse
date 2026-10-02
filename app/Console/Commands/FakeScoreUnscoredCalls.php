<?php

namespace App\Console\Commands;

use App\Models\Call;
use App\Models\CallAnalysis;
use App\Services\CallScoringService;
use App\Services\CallSegmentScoreBackfillService;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * TEMPORARY / MANUAL DATA-REPAIR TOOL — not part of the normal scoring pipeline.
 *
 * Backfills fake AnalyzeCallJob-shaped scores for calls stuck after Gemini rate limits
 * (missing call-level score, and/or missing per-segment scores). Does not call Gemini.
 */
#[Signature('calls:fake-score
    {--dry-run : List which calls/segments would be affected without writing anything}
    {--limit= : Optional cap on number of calls processed in one run}')]
#[Description('TEMPORARY: backfill fake AnalyzeCallJob-shaped scores for unscored / partially scored calls (no Gemini)')]
class FakeScoreUnscoredCalls extends Command
{
    public function handle(
        CallScoringService $scoring,
        CallSegmentScoreBackfillService $segmentBackfill,
    ): int {
        // Temporary repair entrypoint — keep out of scheduled jobs / normal upload flow.
        $dryRun = (bool) $this->option('dry-run');
        $limitOption = $this->option('limit');
        $limit = is_numeric($limitOption) ? max(0, (int) $limitOption) : null;

        $caseAIds = [];
        $caseBIds = [];
        $skipped = 0;
        $failed = 0;
        $failedIds = [];
        $processed = 0;

        $query = Call::query()
            ->with('analysis')
            ->whereHas('analysis')
            ->orderBy('id');

        $this->info($dryRun
            ? 'Dry run: listing calls that would receive fake scores (no writes).'
            : 'Writing fake AnalyzeCallJob-shaped scores (TEMPORARY repair — not Gemini).');

        $query->chunkById(50, function ($calls) use (
            $scoring,
            $segmentBackfill,
            $dryRun,
            $limit,
            &$caseAIds,
            &$caseBIds,
            &$skipped,
            &$failed,
            &$failedIds,
            &$processed,
        ): bool {
            foreach ($calls as $call) {
                if ($limit !== null && $processed >= $limit) {
                    return false;
                }

                $analysis = $call->analysis;

                if (! $analysis instanceof CallAnalysis) {
                    $skipped++;
                    $this->warn("Skipped call #{$call->id}: no analysis row.");

                    continue;
                }

                $case = $this->resolveCase($analysis, $segmentBackfill);

                if ($case === null) {
                    continue;
                }

                /** @var list<array<string, mixed>> $segments */
                $segments = $analysis->transcript_segments ?? [];

                if ($case === 'A' && $segments === []) {
                    $this->warn("Call #{$call->id} (Case A): no transcript segments — will fake call-level scores only.");
                }

                $missingSegmentIndexes = $case === 'B'
                    ? $this->missingSegmentScoreIndexes($segments, $segmentBackfill)
                    : [];

                if ($dryRun) {
                    if ($case === 'A') {
                        $this->line("  [Case A] Call #{$call->id} — fake overall + ".count($segments).' segment score(s); status → completed');
                        $caseAIds[] = $call->id;
                    } else {
                        $this->line('  [Case B] Call #'.$call->id.' — fake scores for segments: ['.implode(', ', $missingSegmentIndexes).']; leave call-level score; status → completed');
                        $caseBIds[] = $call->id;
                    }

                    $processed++;

                    continue;
                }

                try {
                    DB::transaction(function () use (
                        $call,
                        $analysis,
                        $case,
                        $scoring,
                        $segmentBackfill,
                        $segments,
                    ): void {
                        if ($case === 'A') {
                            $this->applyCaseA($call, $analysis, $scoring, $segmentBackfill, $segments);
                        } else {
                            $this->applyCaseB($call, $analysis, $segmentBackfill, $segments);
                        }
                    });

                    if ($case === 'A') {
                        $caseAIds[] = $call->id;
                        $this->info("Case A: fake-scored call #{$call->id}.");
                    } else {
                        $caseBIds[] = $call->id;
                        $this->info("Case B: fake-scored missing segments for call #{$call->id}.");
                    }

                    $processed++;
                } catch (Throwable $exception) {
                    $failed++;
                    $failedIds[] = $call->id;
                    Log::error('calls:fake-score failed for call.', [
                        'call_id' => $call->id,
                        'message' => $exception->getMessage(),
                        'exception' => $exception::class,
                    ]);
                    $this->error("Failed call #{$call->id}: {$exception->getMessage()}");
                }
            }

            return $limit === null || $processed < $limit;
        });

        $affectedIds = array_values(array_unique([...$caseAIds, ...$caseBIds, ...$failedIds]));
        sort($affectedIds);

        $this->newLine();
        $this->info('Summary (TEMPORARY fake-score repair):');
        $this->line('  Case A (full fake score): '.count($caseAIds));
        $this->line('  Case B (segments only):   '.count($caseBIds));
        $this->line('  Skipped:                  '.$skipped);
        $this->line('  Failed:                   '.$failed);
        $this->line('  Affected call IDs:        '.(
            $affectedIds === [] ? '(none)' : implode(', ', $affectedIds)
        ));

        return $failed > 0 ? self::FAILURE : self::SUCCESS;
    }

    /**
     * @return 'A'|'B'|null
     */
    private function resolveCase(CallAnalysis $analysis, CallSegmentScoreBackfillService $segmentBackfill): ?string
    {
        if ($analysis->total_score === null) {
            return 'A';
        }

        /** @var list<array<string, mixed>> $segments */
        $segments = $analysis->transcript_segments ?? [];

        if ($segments === []) {
            return null;
        }

        if ($segmentBackfill->analysisNeedsSegmentScores($analysis)) {
            return 'B';
        }

        return null;
    }

    /**
     * @param  list<array<string, mixed>>  $segments
     * @return list<int>
     */
    private function missingSegmentScoreIndexes(array $segments, CallSegmentScoreBackfillService $segmentBackfill): array
    {
        $indexes = [];

        foreach ($segments as $index => $segment) {
            if (! $segmentBackfill->segmentHasAiScores($segment)) {
                $indexes[] = $index;
            }
        }

        return $indexes;
    }

    /**
     * Case A: missing call-level score — mirror AnalyzeCallJob write shape with fake values.
     *
     * @param  list<array<string, mixed>>  $segments
     */
    private function applyCaseA(
        Call $call,
        CallAnalysis $analysis,
        CallScoringService $scoring,
        CallSegmentScoreBackfillService $segmentBackfill,
        array $segments,
    ): void {
        $qualityScores = $this->generateQualityScores();
        $interruptionCounts = $this->generateInterruptionCounts(count($segments));
        $inappropriateWordsCount = $scoring->countInappropriateWords(
            trim((string) ($analysis->transcript ?? '')),
            $call->language,
        );
        $inappropriateCounts = [
            'inappropriate_words_count' => $inappropriateWordsCount,
            'operator_inappropriate_words_count' => null,
            'customer_inappropriate_words_count' => null,
        ];
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

        $payload = [
            'courtesy_score' => $qualityScores['courtesy_score'],
            'rudeness_score' => $qualityScores['rudeness_score'],
            'emotional_tone_score' => $qualityScores['emotional_tone_score'],
            'interruption_count' => $interruptionCounts['interruption_count'],
            'operator_interruption_count' => $interruptionCounts['operator_interruption_count'],
            'customer_interruption_count' => $interruptionCounts['customer_interruption_count'],
            'inappropriate_words_count' => $inappropriateWordsCount,
            'operator_inappropriate_words_count' => null,
            'customer_inappropriate_words_count' => null,
            'inappropriate_expressions' => null,
            'total_score' => $totalScore,
            'has_rudeness' => $qualityScores['rudeness_score'] >= 40,
            'has_interruption' => $interruptionCounts['interruption_count'] > 0,
            'is_fake_scored' => true,
            'ai_summary' => CallAnalysis::FAKE_SCORE_SUMMARY,
            'emotion_label' => $analysis->emotion_label ?: 'fallback',
            'buyer_potential_label' => $analysis->buyer_potential_label ?: 'fallback',
            'analyzed_at' => now(),
        ];

        if ($segments !== []) {
            $payload['transcript_segments'] = $this->enrichSegmentsWithFakeScores(
                $segments,
                $segmentBackfill,
                fillAll: true,
            );
        }

        $analysis->update($payload);

        // Same terminal status TranscribeCallJob sets after a successful pipeline run.
        $call->update([
            'status' => Call::STATUS_COMPLETED,
            'error_message' => null,
        ]);
    }

    /**
     * Case B: call-level score exists — only fill segments missing Gemini quality scores.
     *
     * @param  list<array<string, mixed>>  $segments
     */
    private function applyCaseB(
        Call $call,
        CallAnalysis $analysis,
        CallSegmentScoreBackfillService $segmentBackfill,
        array $segments,
    ): void {
        $enriched = $this->enrichSegmentsWithFakeScores(
            $segments,
            $segmentBackfill,
            fillAll: false,
        );

        $analysis->update([
            'transcript_segments' => $enriched,
            // Call-level Gemini scores stay real, but the analysis is marked so UI/filter can surface backfilled segments.
            'is_fake_scored' => true,
        ]);

        $call->update([
            'status' => Call::STATUS_COMPLETED,
            'error_message' => null,
        ]);
    }

    /**
     * @return array{courtesy_score: int, rudeness_score: int, emotional_tone_score: int}
     */
    private function generateQualityScores(): array
    {
        // Biased toward plausible “ok call” ranges so weighted totals look coherent.
        return [
            'courtesy_score' => random_int(55, 95),
            'rudeness_score' => random_int(0, 35),
            'emotional_tone_score' => random_int(0, 35),
        ];
    }

    /**
     * Fake sözkəsmə counts at call level (AnalyzeCallJob stores counts, not per-segment flags).
     *
     * @return array{interruption_count: int, operator_interruption_count: int, customer_interruption_count: int}
     */
    private function generateInterruptionCounts(int $segmentCount): array
    {
        if ($segmentCount < 2) {
            return [
                'interruption_count' => 0,
                'operator_interruption_count' => 0,
                'customer_interruption_count' => 0,
            ];
        }

        $ratePercent = random_int(5, 15);
        $interruptionCount = (int) round($segmentCount * ($ratePercent / 100));
        $interruptionCount = max(0, min($segmentCount - 1, $interruptionCount));

        $operatorCount = $interruptionCount === 0 ? 0 : random_int(0, $interruptionCount);
        $customerCount = $interruptionCount - $operatorCount;

        return [
            'interruption_count' => $interruptionCount,
            'operator_interruption_count' => $operatorCount,
            'customer_interruption_count' => $customerCount,
        ];
    }

    /**
     * @param  list<array<string, mixed>>  $segments
     * @return list<array<string, mixed>>
     */
    private function enrichSegmentsWithFakeScores(
        array $segments,
        CallSegmentScoreBackfillService $segmentBackfill,
        bool $fillAll,
    ): array {
        $enriched = [];

        foreach ($segments as $segment) {
            if ($fillAll || ! $segmentBackfill->segmentHasAiScores($segment)) {
                $scores = $this->generateQualityScores();
                $enriched[] = array_merge($segment, $scores, [
                    'is_fake_scored' => true,
                ]);
            } else {
                $enriched[] = $segment;
            }
        }

        return $enriched;
    }
}
