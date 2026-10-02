<?php

namespace Tests\Feature;

use App\Models\Call;
use App\Models\CallAnalysis;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class CallIndexTest extends TestCase
{
    use RefreshDatabase;

    public function test_index_requires_authentication(): void
    {
        $this->getJson('/api/calls')
            ->assertUnauthorized();
    }

    public function test_index_returns_filtered_and_total_record_counts(): void
    {
        $user = User::factory()->create();

        Call::factory()->create(['customer_name' => 'Ali Məmmədov', 'status' => Call::STATUS_COMPLETED]);
        Call::factory()->create(['customer_name' => 'Ali Vəliyev', 'status' => Call::STATUS_PENDING]);
        Call::factory()->create(['customer_name' => 'Leyla Həsənova', 'status' => Call::STATUS_FAILED]);

        $this->actingAs($user)
            ->getJson('/api/calls?search=Ali')
            ->assertOk()
            ->assertJsonPath('total_count', 3)
            ->assertJsonPath('calls.total', 2);
    }

    public function test_index_total_count_matches_unfiltered_calls_when_filters_clear(): void
    {
        $user = User::factory()->create();

        Call::factory()->count(4)->create();

        $this->actingAs($user)
            ->getJson('/api/calls')
            ->assertOk()
            ->assertJsonPath('total_count', 4)
            ->assertJsonPath('calls.total', 4);
    }

    public function test_index_includes_is_fake_scored_and_filters_by_fake_scored(): void
    {
        $user = User::factory()->create();

        $fakeCall = Call::factory()->create(['status' => Call::STATUS_COMPLETED]);
        CallAnalysis::query()->create([
            'call_id' => $fakeCall->id,
            'transcript' => 'Fake scored',
            'total_score' => 70,
            'is_fake_scored' => true,
            'ai_summary' => CallAnalysis::FAKE_SCORE_SUMMARY,
        ]);

        $realCall = Call::factory()->create(['status' => Call::STATUS_COMPLETED]);
        CallAnalysis::query()->create([
            'call_id' => $realCall->id,
            'transcript' => 'Real scored',
            'total_score' => 88,
            'is_fake_scored' => false,
            'ai_summary' => 'Gemini summary',
        ]);

        Call::factory()->create(['status' => Call::STATUS_PENDING]);

        $this->actingAs($user)
            ->getJson('/api/calls')
            ->assertOk()
            ->assertJsonPath('total_count', 3)
            ->assertJsonFragment(['id' => $fakeCall->id, 'is_fake_scored' => true])
            ->assertJsonFragment(['id' => $realCall->id, 'is_fake_scored' => false]);

        $this->actingAs($user)
            ->getJson('/api/calls?fake_scored=fake')
            ->assertOk()
            ->assertJsonPath('total_count', 3)
            ->assertJsonPath('calls.total', 1)
            ->assertJsonPath('calls.data.0.id', $fakeCall->id)
            ->assertJsonPath('calls.data.0.is_fake_scored', true);

        $this->actingAs($user)
            ->getJson('/api/calls?fake_scored=real')
            ->assertOk()
            ->assertJsonPath('calls.total', 2)
            ->assertJsonMissing(['id' => $fakeCall->id, 'is_fake_scored' => true]);
    }

    public function test_show_includes_is_fake_scored_on_analysis(): void
    {
        $user = User::factory()->create();

        $call = Call::factory()->create(['status' => Call::STATUS_COMPLETED]);
        CallAnalysis::query()->create([
            'call_id' => $call->id,
            'transcript' => 'Buyurun.',
            'transcript_segments' => [
                [
                    'speaker' => 'operator',
                    'start' => 0.0,
                    'end' => 1.0,
                    'text' => 'Buyurun.',
                    'courtesy_score' => 80,
                    'rudeness_score' => 10,
                    'emotional_tone_score' => 75,
                    'is_fake_scored' => true,
                ],
            ],
            'total_score' => 72,
            'is_fake_scored' => true,
            'ai_summary' => CallAnalysis::FAKE_SCORE_SUMMARY,
        ]);

        $this->actingAs($user)
            ->getJson('/api/calls/'.$call->id)
            ->assertOk()
            ->assertJsonPath('call.analysis.is_fake_scored', true)
            ->assertJsonPath('call.analysis.transcript_segments.0.is_fake_scored', true);
    }

    public function test_index_filters_by_transcription_provider_alone(): void
    {
        $user = User::factory()->create();

        $gemini = Call::factory()->create([
            'customer_name' => 'Gemini zəng',
            'transcription_provider' => 'gemini',
        ]);
        Call::factory()->create([
            'customer_name' => 'Eleven zəng',
            'transcription_provider' => 'elevenlabs',
        ]);

        $this->actingAs($user)
            ->getJson('/api/calls?transcription_provider=gemini')
            ->assertOk()
            ->assertJsonPath('calls.total', 1)
            ->assertJsonPath('calls.data.0.id', $gemini->id)
            ->assertJsonPath('total_count', 2);
    }

    public function test_index_filters_by_analysis_provider_alone(): void
    {
        $user = User::factory()->create();

        $openai = Call::factory()->create([
            'analysis_provider' => 'openai',
        ]);
        Call::factory()->create([
            'analysis_provider' => 'gemini',
        ]);

        $this->actingAs($user)
            ->getJson('/api/calls?analysis_provider=openai')
            ->assertOk()
            ->assertJsonPath('calls.total', 1)
            ->assertJsonPath('calls.data.0.id', $openai->id);
    }

    public function test_index_combines_provider_filters(): void
    {
        $user = User::factory()->create();

        $match = Call::factory()->create([
            'transcription_provider' => 'elevenlabs',
            'analysis_provider' => 'openai',
        ]);
        Call::factory()->create([
            'transcription_provider' => 'elevenlabs',
            'analysis_provider' => 'gemini',
        ]);
        Call::factory()->create([
            'transcription_provider' => 'gemini',
            'analysis_provider' => 'openai',
        ]);

        $this->actingAs($user)
            ->getJson('/api/calls?transcription_provider=elevenlabs&analysis_provider=openai')
            ->assertOk()
            ->assertJsonPath('calls.total', 1)
            ->assertJsonPath('calls.data.0.id', $match->id)
            ->assertJsonPath('total_count', 3);
    }

    public function test_index_combines_provider_filters_with_search_and_status(): void
    {
        $user = User::factory()->create();

        $match = Call::factory()->create([
            'customer_name' => 'Ali Məmmədov',
            'status' => Call::STATUS_COMPLETED,
            'transcription_provider' => 'gemini',
            'analysis_provider' => 'openai',
        ]);
        Call::factory()->create([
            'customer_name' => 'Ali Vəliyev',
            'status' => Call::STATUS_PENDING,
            'transcription_provider' => 'gemini',
            'analysis_provider' => 'openai',
        ]);
        Call::factory()->create([
            'customer_name' => 'Leyla Həsənova',
            'status' => Call::STATUS_COMPLETED,
            'transcription_provider' => 'gemini',
            'analysis_provider' => 'openai',
        ]);

        $this->actingAs($user)
            ->getJson('/api/calls?search=Ali&status=completed&transcription_provider=gemini&analysis_provider=openai')
            ->assertOk()
            ->assertJsonPath('calls.total', 1)
            ->assertJsonPath('calls.data.0.id', $match->id)
            ->assertJsonPath('total_count', 3);
    }

    public function test_index_pagination_links_keep_provider_filters(): void
    {
        $user = User::factory()->create();

        $older = Call::factory()->create([
            'customer_name' => 'Köhnə',
            'transcription_provider' => 'gemini',
            'analysis_provider' => 'openai',
        ]);
        $older->forceFill(['created_at' => now()->subMinutes(2)])->save();

        $newer = Call::factory()->create([
            'customer_name' => 'Yeni',
            'transcription_provider' => 'gemini',
            'analysis_provider' => 'openai',
        ]);
        $newer->forceFill(['created_at' => now()->subMinute()])->save();

        Call::factory()->create([
            'transcription_provider' => 'elevenlabs',
            'analysis_provider' => 'gemini',
        ]);

        $response = $this->actingAs($user)
            ->getJson('/api/calls?transcription_provider=gemini&analysis_provider=openai&per_page=1');

        $response->assertOk()
            ->assertJsonPath('calls.total', 2)
            ->assertJsonPath('calls.data.0.id', $newer->id);

        $next = $response->json('calls.next_page_url');
        $this->assertIsString($next);
        $this->assertStringContainsString('transcription_provider=gemini', $next);
        $this->assertStringContainsString('analysis_provider=openai', $next);
        $this->assertStringContainsString('per_page=1', $next);
        $this->assertStringContainsString('page=2', $next);
    }

    public function test_index_rejects_unknown_transcription_provider_with_422(): void
    {
        $user = User::factory()->create();

        $this->actingAs($user)
            ->getJson('/api/calls?transcription_provider=whisper')
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['transcription_provider']);
    }

    public function test_show_includes_provider_columns(): void
    {
        $user = User::factory()->create();
        $call = Call::factory()->create([
            'status' => Call::STATUS_COMPLETED,
            'transcription_provider' => 'elevenlabs',
            'transcription_model' => 'scribe_v1',
            'analysis_provider' => 'openai',
            'analysis_model' => 'gpt-6-luna',
        ]);

        $this->actingAs($user)
            ->getJson('/api/calls/'.$call->id)
            ->assertOk()
            ->assertJsonPath('call.transcription_provider', 'elevenlabs')
            ->assertJsonPath('call.transcription_model', 'scribe_v1')
            ->assertJsonPath('call.analysis_provider', 'openai')
            ->assertJsonPath('call.analysis_model', 'gpt-6-luna');
    }
}
