<?php

namespace Tests\Feature;

use App\Models\Setting;
use App\Models\User;
use App\Services\CallScoringService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class SettingUpdateTest extends TestCase
{
    use RefreshDatabase;

    public function test_update_requires_authentication(): void
    {
        $this->putJson('/api/settings', $this->settingsPayload())
            ->assertUnauthorized();
    }

    public function test_update_persists_independent_parameters_that_do_not_sum_to_one_hundred(): void
    {
        $user = User::factory()->create();

        $payload = $this->settingsPayload([
            'weight_courtesy' => 40,
            'weight_operator_interruption' => 8,
            'weight_customer_interruption' => 7,
            'weight_rudeness' => 10,
            'weight_emotion' => 10,
            'weight_operator_inappropriate' => 5,
            'weight_customer_inappropriate' => 7,
        ]);

        $this->actingAs($user)
            ->putJson('/api/settings', $payload)
            ->assertOk()
            ->assertJsonPath('message', 'Ayarlar saxlanıldı.');

        $this->assertSame('40', Setting::getValue('weight_courtesy'));
        $this->assertSame('8', Setting::getValue('weight_operator_interruption'));
        $this->assertSame('7', Setting::getValue('weight_customer_interruption'));
        $this->assertSame('5', Setting::getValue('weight_operator_inappropriate'));
        $this->assertSame('7', Setting::getValue('weight_customer_inappropriate'));
        $this->assertDatabaseHas('settings', [
            'key' => 'weight_courtesy',
            'group' => 'scoring_params',
            'value' => '40',
        ]);
        $this->assertDatabaseMissing('settings', [
            'key' => 'weight_interruption',
        ]);
        $this->assertDatabaseMissing('settings', [
            'key' => 'weight_buyer_potential',
        ]);
        $this->assertDatabaseMissing('settings', [
            'key' => 'weight_aggression',
        ]);
        $this->assertDatabaseMissing('settings', [
            'key' => 'weight_inappropriate',
        ]);
    }

    public function test_show_omits_removed_weight_keys(): void
    {
        $user = User::factory()->create();

        $settings = $this->actingAs($user)
            ->getJson('/api/settings')
            ->assertOk()
            ->json('settings');

        $this->assertIsArray($settings);
        $this->assertArrayNotHasKey('weight_aggression', $settings);
        $this->assertArrayNotHasKey('weight_interruption', $settings);
        $this->assertArrayNotHasKey('weight_buyer_potential', $settings);
        $this->assertArrayHasKey('weight_rudeness', $settings);
        $this->assertArrayHasKey('weight_operator_interruption', $settings);
        $this->assertArrayHasKey('weight_customer_interruption', $settings);
        $this->assertArrayHasKey('weight_operator_inappropriate', $settings);
        $this->assertArrayHasKey('weight_customer_inappropriate', $settings);
        $this->assertSame('1.5', $settings['score_gamma']);
        $this->assertArrayNotHasKey('weight_inappropriate', $settings);
        $this->assertSame('gemini', $settings['transcription_provider']);
        $this->assertSame('gemini', $settings['analysis_provider']);
    }

    public function test_update_rejects_elevenlabs_when_the_api_key_is_missing(): void
    {
        config(['services.elevenlabs.key' => '']);

        $user = User::factory()->create();

        $this->actingAs($user)
            ->putJson('/api/settings', $this->settingsPayload([
                'transcription_provider' => 'elevenlabs',
            ]))
            ->assertUnprocessable()
            ->assertJsonValidationErrors([
                'transcription_provider' => 'ELEVENLABS_API_KEY təyin edilməyib',
            ]);
    }

    public function test_update_rejects_openai_when_the_api_key_is_missing(): void
    {
        config(['services.openai.key' => '']);

        $user = User::factory()->create();

        $this->actingAs($user)
            ->putJson('/api/settings', $this->settingsPayload([
                'analysis_provider' => 'openai',
            ]))
            ->assertUnprocessable()
            ->assertJsonValidationErrors([
                'analysis_provider' => 'OPENAI_API_KEY təyin edilməyib',
            ]);
    }

    public function test_update_rejects_unknown_provider_with_422(): void
    {
        $user = User::factory()->create();

        $this->actingAs($user)
            ->putJson('/api/settings', $this->settingsPayload([
                'transcription_provider' => 'whisper',
            ]))
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['transcription_provider']);
    }

    public function test_update_persists_selected_providers_when_api_keys_are_configured(): void
    {
        config([
            'services.elevenlabs.key' => 'test-elevenlabs-key',
            'services.openai.key' => 'test-openai-key',
        ]);

        $user = User::factory()->create();

        $this->actingAs($user)
            ->putJson('/api/settings', $this->settingsPayload([
                'transcription_provider' => 'elevenlabs',
                'analysis_provider' => 'openai',
            ]))
            ->assertOk();

        $this->assertSame('elevenlabs', Setting::getValue('transcription_provider'));
        $this->assertSame('openai', Setting::getValue('analysis_provider'));
        $this->assertDatabaseHas('settings', [
            'key' => 'transcription_provider',
            'value' => 'elevenlabs',
            'group' => 'general',
        ]);
        $this->assertDatabaseHas('settings', [
            'key' => 'analysis_provider',
            'value' => 'openai',
            'group' => 'general',
        ]);
    }

    public function test_update_persists_score_gamma_and_rejects_values_outside_the_range(): void
    {
        $user = User::factory()->create();

        $this->actingAs($user)
            ->putJson('/api/settings', $this->settingsPayload([
                'score_gamma' => 2.0,
            ]))
            ->assertOk();

        $this->assertSame('2', Setting::getValue('score_gamma'));
        $this->assertDatabaseHas('settings', [
            'key' => 'score_gamma',
            'group' => 'scoring_params',
            'value' => '2',
        ]);

        $this->actingAs($user)
            ->putJson('/api/settings', $this->settingsPayload([
                'score_gamma' => 0.9,
            ]))
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['score_gamma']);

        $this->actingAs($user)
            ->putJson('/api/settings', $this->settingsPayload([
                'score_gamma' => 3.1,
            ]))
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['score_gamma']);
    }

    public function test_update_rejects_parameter_above_one_hundred_with_422(): void
    {
        $user = User::factory()->create();

        $this->actingAs($user)
            ->putJson('/api/settings', $this->settingsPayload([
                'weight_courtesy' => 101,
            ]))
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['weight_courtesy']);
    }

    /**
     * @param  array<string, mixed>  $overrides
     * @return array<string, mixed>
     */
    private function settingsPayload(array $overrides = []): array
    {
        $weights = [];

        foreach (CallScoringService::WEIGHT_DEFAULTS as $key => $value) {
            $weights[$key] = $value;
        }

        return [
            ...$weights,
            'score_gamma' => 1.5,
            'alert_score_threshold' => 40,
            'default_language' => 'az',
            'audio_retention_days' => 90,
            'transcription_provider' => 'gemini',
            'analysis_provider' => 'gemini',
            ...$overrides,
        ];
    }
}
