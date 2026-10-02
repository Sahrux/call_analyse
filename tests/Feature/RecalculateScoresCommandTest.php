<?php

namespace Tests\Feature;

use App\Models\Call;
use App\Models\CallAnalysis;
use App\Models\Setting;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Tests\TestCase;

class RecalculateScoresCommandTest extends TestCase
{
    use RefreshDatabase;

    public function test_recalculate_command_rewrites_stored_overall_scores_from_current_settings(): void
    {
        Setting::setValue('weight_courtesy', 10, 'scoring_params');
        Setting::setValue('weight_operator_interruption', 10, 'scoring_params');
        Setting::setValue('weight_customer_interruption', 20, 'scoring_params');
        Setting::setValue('weight_rudeness', 30, 'scoring_params');
        Setting::setValue('weight_emotion', 10, 'scoring_params');
        Setting::setValue('weight_operator_inappropriate', 10, 'scoring_params');
        Setting::setValue('weight_customer_inappropriate', 30, 'scoring_params');
        Setting::setValue('score_gamma', '1.5', 'scoring_params');

        $call = Call::factory()->create();
        $analysis = CallAnalysis::query()->create([
            'call_id' => $call->id,
            'courtesy_score' => 45,
            'rudeness_score' => 48,
            'emotional_tone_score' => 68,
            'operator_interruption_count' => 0,
            'customer_interruption_count' => 0,
            'operator_inappropriate_words_count' => 1,
            'customer_inappropriate_words_count' => 1,
            'inappropriate_words_count' => 2,
            'total_score' => 99,
            'manual_courtesy_score' => 100,
            'manual_operator_interruption_score' => 100,
            'manual_customer_interruption_score' => 100,
            'manual_rudeness_score' => 0,
            'manual_emotional_tone_score' => 0,
            'manual_operator_inappropriate_score' => 100,
            'manual_customer_inappropriate_score' => 100,
            'manual_score' => 1,
        ]);

        $unscoredCall = Call::factory()->create();
        $unscored = CallAnalysis::query()->create([
            'call_id' => $unscoredCall->id,
            'total_score' => null,
        ]);

        $exitCode = Artisan::call('scores:recalculate');

        $analysis->refresh();
        $unscored->refresh();

        $this->assertSame(0, $exitCode);
        $this->assertSame(20, $analysis->total_score);
        $this->assertSame(100, $analysis->manual_score);
        $this->assertNull($unscored->total_score);
    }
}
