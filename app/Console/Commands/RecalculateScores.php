<?php

namespace App\Console\Commands;

use App\Models\CallAnalysis;
use App\Services\CallScoringService;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;

#[Signature('scores:recalculate')]
#[Description('Recalculate stored overall scores from the current scoring settings')]
class RecalculateScores extends Command
{
    public function handle(CallScoringService $scoring): int
    {
        $weights = $scoring->overallWeights();
        $gamma = $scoring->gamma();
        $updated = 0;

        CallAnalysis::query()->chunkById(100, function ($analyses) use ($scoring, $weights, $gamma, &$updated): void {
            foreach ($analyses as $analysis) {
                $dirty = false;

                if ($this->hasQualityScores($analysis)) {
                    $analysis->total_score = $scoring->calculateOverall(
                        $scoring->overallInputsFromAnalysis($analysis),
                        $weights,
                        $gamma,
                    );
                    $dirty = true;
                }

                if ($this->hasManualScores($analysis)) {
                    $analysis->manual_score = $scoring->calculateTotalScore(
                        courtesyScore: (int) $analysis->manual_courtesy_score,
                        operatorInterruptionScore: (int) $analysis->manual_operator_interruption_score,
                        customerInterruptionScore: (int) $analysis->manual_customer_interruption_score,
                        rudenessScore: (int) $analysis->manual_rudeness_score,
                        emotionalToneScore: (int) $analysis->manual_emotional_tone_score,
                        operatorInappropriateScore: (int) $analysis->manual_operator_inappropriate_score,
                        customerInappropriateScore: (int) $analysis->manual_customer_inappropriate_score,
                    );
                    $dirty = true;
                }

                if ($dirty && $analysis->isDirty()) {
                    $analysis->save();
                    $updated++;
                }
            }
        });

        $this->info("Recalculated overall scores for {$updated} analyses.");

        return self::SUCCESS;
    }

    private function hasQualityScores(CallAnalysis $analysis): bool
    {
        return $analysis->courtesy_score !== null
            && $analysis->rudeness_score !== null
            && $analysis->emotional_tone_score !== null;
    }

    private function hasManualScores(CallAnalysis $analysis): bool
    {
        return $analysis->manual_courtesy_score !== null
            && $analysis->manual_operator_interruption_score !== null
            && $analysis->manual_customer_interruption_score !== null
            && $analysis->manual_rudeness_score !== null
            && $analysis->manual_emotional_tone_score !== null
            && $analysis->manual_operator_inappropriate_score !== null
            && $analysis->manual_customer_inappropriate_score !== null;
    }
}
