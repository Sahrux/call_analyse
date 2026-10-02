<?php

namespace Tests\Feature;

use App\Models\Call;
use App\Models\CallAnalysis;
use App\Models\Setting;
use App\Models\User;
use App\Services\CallScoringService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class CallReviewTest extends TestCase
{
    use RefreshDatabase;

    public function test_update_review_requires_authentication(): void
    {
        $call = Call::factory()->create();

        $this->patchJson("/api/calls/{$call->id}/review", $this->reviewPayload())
            ->assertUnauthorized();
    }

    public function test_update_review_persists_parameter_scores_and_computed_overall_score(): void
    {
        $this->seedDefaultWeights();

        $user = User::factory()->create();
        $call = Call::factory()->create();

        CallAnalysis::query()->create(['call_id' => $call->id]);

        $this->actingAs($user)
            ->patchJson("/api/calls/{$call->id}/review", $this->reviewPayload([
                'manual_courtesy_score' => 80,
                'manual_operator_interruption_score' => 100,
                'manual_customer_interruption_score' => 90,
                'manual_rudeness_score' => 20,
                'manual_emotional_tone_score' => 75,
                'manual_operator_inappropriate_score' => 80,
                'manual_customer_inappropriate_score' => 80,
                'manual_note' => 'Yoxlanıldı',
                'manual_score' => 1,
            ]))
            ->assertOk()
            ->assertJsonPath('message', 'Manual qiymətləndirmə saxlanıldı.');

        $analysis = CallAnalysis::query()->where('call_id', $call->id)->first();

        $this->assertNotNull($analysis);
        $this->assertSame(80, $analysis->manual_courtesy_score);
        $this->assertSame(100, $analysis->manual_operator_interruption_score);
        $this->assertSame(90, $analysis->manual_customer_interruption_score);
        $this->assertSame(20, $analysis->manual_rudeness_score);
        $this->assertSame(75, $analysis->manual_emotional_tone_score);
        $this->assertSame(80, $analysis->manual_operator_inappropriate_score);
        $this->assertSame(80, $analysis->manual_customer_inappropriate_score);
        $this->assertSame(38, $analysis->manual_score);
        $this->assertSame('Yoxlanıldı', $analysis->manual_note);
        $this->assertSame($user->id, $analysis->reviewed_by);
    }

    public function test_update_review_creates_analysis_when_missing(): void
    {
        $this->seedDefaultWeights();

        $user = User::factory()->create();
        $call = Call::factory()->create();

        $this->actingAs($user)
            ->patchJson("/api/calls/{$call->id}/review", $this->reviewPayload())
            ->assertOk();

        $this->assertDatabaseHas('call_analyses', [
            'call_id' => $call->id,
            'manual_courtesy_score' => 80,
            'manual_score' => 38,
        ]);
    }

    public function test_update_review_rejects_missing_parameter_scores_with_422(): void
    {
        $user = User::factory()->create();
        $call = Call::factory()->create();

        $this->actingAs($user)
            ->patchJson("/api/calls/{$call->id}/review", [
                'manual_note' => 'Qeyd',
            ])
            ->assertUnprocessable()
            ->assertJsonValidationErrors([
                'manual_courtesy_score',
                'manual_operator_interruption_score',
                'manual_customer_interruption_score',
                'manual_rudeness_score',
                'manual_emotional_tone_score',
                'manual_operator_inappropriate_score',
                'manual_customer_inappropriate_score',
            ]);

        $this->assertDatabaseMissing('call_analyses', ['call_id' => $call->id]);
    }

    public function test_update_review_rejects_parameter_above_one_hundred_with_422(): void
    {
        $user = User::factory()->create();
        $call = Call::factory()->create();

        CallAnalysis::query()->create(['call_id' => $call->id]);

        $this->actingAs($user)
            ->patchJson("/api/calls/{$call->id}/review", $this->reviewPayload([
                'manual_courtesy_score' => 101,
            ]))
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['manual_courtesy_score']);

        $this->assertDatabaseHas('call_analyses', [
            'call_id' => $call->id,
            'manual_score' => null,
            'manual_courtesy_score' => null,
        ]);
    }

    public function test_show_includes_manual_parameter_scores_and_score_weights(): void
    {
        $this->seedDefaultWeights();

        $user = User::factory()->create();
        $call = Call::factory()->create();

        CallAnalysis::query()->create([
            'call_id' => $call->id,
            'manual_courtesy_score' => 80,
            'manual_operator_interruption_score' => 100,
            'manual_customer_interruption_score' => 90,
            'manual_rudeness_score' => 20,
            'manual_emotional_tone_score' => 75,
            'manual_operator_inappropriate_score' => 80,
            'manual_customer_inappropriate_score' => 70,
            'manual_score' => 83,
            'inappropriate_words_count' => 3,
            'operator_inappropriate_words_count' => 1,
            'customer_inappropriate_words_count' => 2,
            'inappropriate_expressions' => [
                ['speaker' => 'operator', 'text' => 'axmaq'],
                ['speaker' => 'musteri', 'text' => 'lanət'],
                ['speaker' => 'musteri', 'text' => 'debil'],
            ],
        ]);

        $this->actingAs($user)
            ->getJson("/api/calls/{$call->id}")
            ->assertOk()
            ->assertJsonPath('call.analysis.manual_courtesy_score', 80)
            ->assertJsonPath('call.analysis.manual_operator_inappropriate_score', 80)
            ->assertJsonPath('call.analysis.manual_customer_inappropriate_score', 70)
            ->assertJsonPath('call.analysis.inappropriate_words_count', 3)
            ->assertJsonPath('call.analysis.operator_inappropriate_words_count', 1)
            ->assertJsonPath('call.analysis.customer_inappropriate_words_count', 2)
            ->assertJsonPath('call.analysis.inappropriate_expressions.0.speaker', 'operator')
            ->assertJsonPath('call.analysis.manual_score', 83)
            ->assertJsonPath('score_weights.weight_courtesy', 25)
            ->assertJsonPath('score_weights.weight_operator_interruption', 10)
            ->assertJsonPath('score_weights.weight_operator_inappropriate', 7)
            ->assertJsonPath('score_weights.weight_customer_inappropriate', 8);
    }

    /**
     * @param  array<string, mixed>  $overrides
     * @return array<string, mixed>
     */
    private function reviewPayload(array $overrides = []): array
    {
        return [
            'manual_courtesy_score' => 80,
            'manual_operator_interruption_score' => 100,
            'manual_customer_interruption_score' => 90,
            'manual_rudeness_score' => 20,
            'manual_emotional_tone_score' => 75,
            'manual_operator_inappropriate_score' => 80,
            'manual_customer_inappropriate_score' => 80,
            'manual_note' => 'Yoxlanıldı',
            ...$overrides,
        ];
    }

    private function seedDefaultWeights(): void
    {
        foreach (CallScoringService::WEIGHT_DEFAULTS as $key => $value) {
            Setting::setValue($key, $value, 'scoring_params');
        }
    }
}
