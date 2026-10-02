<?php

namespace Tests\Feature;

use App\Models\Call;
use App\Models\CallAnalysis;
use App\Models\SerScore;
use App\Models\Setting;
use App\Models\User;
use App\Services\CallScoringService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class CallSerScoreShowTest extends TestCase
{
    use RefreshDatabase;

    public function test_show_includes_latest_ser_score_when_present(): void
    {
        foreach (CallScoringService::WEIGHT_DEFAULTS as $key => $value) {
            Setting::setValue($key, $value, 'scoring_params');
        }

        Setting::setValue('score_gamma', '1.5', 'scoring_params');

        $user = User::factory()->create();
        $call = Call::factory()->create([
            'status' => Call::STATUS_COMPLETED,
        ]);

        CallAnalysis::query()->create([
            'call_id' => $call->id,
            'operator_interruption_count' => 1,
            'customer_interruption_count' => 0,
            'operator_inappropriate_words_count' => 2,
            'customer_inappropriate_words_count' => 1,
            'inappropriate_words_count' => 3,
        ]);

        SerScore::query()->create([
            'call_id' => $call->id,
            'model_version' => 'v1',
            'courtesy_score' => 40.0,
            'rudeness_score' => 20.0,
            'emotional_tone_score' => 55.0,
            'scored_at' => now()->subDay(),
        ]);

        SerScore::query()->create([
            'call_id' => $call->id,
            'model_version' => 'v2',
            'courtesy_score' => 82.5,
            'rudeness_score' => 8.0,
            'emotional_tone_score' => 90.0,
            'scored_at' => now(),
        ]);

        $response = $this->actingAs($user)
            ->getJson("/api/calls/{$call->id}")
            ->assertOk()
            ->assertJsonPath('call.ser_score.model_version', 'v2');

        $serScore = $response->json('call.ser_score');

        $this->assertNotNull($serScore);
        $this->assertEqualsWithDelta(82.5, (float) $serScore['courtesy_score'], 0.001);
        $this->assertEqualsWithDelta(8.0, (float) $serScore['rudeness_score'], 0.001);
        $this->assertEqualsWithDelta(90.0, (float) $serScore['emotional_tone_score'], 0.001);
        $this->assertSame(43, $serScore['ser_overall']);
        $this->assertArrayNotHasKey('model_version', $response->json('call.analysis') ?? []);
    }

    public function test_show_omits_ser_score_gracefully_when_absent(): void
    {
        $user = User::factory()->create();
        $call = Call::factory()->create([
            'status' => Call::STATUS_COMPLETED,
        ]);

        $this->actingAs($user)
            ->getJson("/api/calls/{$call->id}")
            ->assertOk()
            ->assertJsonPath('call.ser_score', null);
    }
}
