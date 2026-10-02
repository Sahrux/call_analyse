<?php

namespace Tests\Feature;

use App\Models\Call;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class CallAudioTest extends TestCase
{
    use RefreshDatabase;

    public function test_show_returns_streaming_audio_url(): void
    {
        Storage::fake('public');
        Storage::disk('public')->put('calls/audio/sample.mp3', str_repeat('a', 500));

        $user = User::factory()->create();
        $call = Call::factory()->create([
            'audio_path' => 'calls/audio/sample.mp3',
        ]);

        $this->actingAs($user)
            ->getJson("/api/calls/{$call->id}")
            ->assertOk()
            ->assertJsonPath('call.audio_url', route('api.calls.audio', $call));
    }

    public function test_audio_endpoint_requires_authentication(): void
    {
        Storage::fake('public');
        Storage::disk('public')->put('calls/audio/sample.mp3', str_repeat('a', 500));

        $call = Call::factory()->create([
            'audio_path' => 'calls/audio/sample.mp3',
        ]);

        $this->get("/api/calls/{$call->id}/audio")
            ->assertUnauthorized();
    }

    public function test_audio_endpoint_returns_not_found_when_file_missing(): void
    {
        $user = User::factory()->create();
        $call = Call::factory()->create([
            'audio_path' => 'calls/audio/missing.mp3',
        ]);

        $this->actingAs($user)
            ->get("/api/calls/{$call->id}/audio")
            ->assertNotFound();
    }

    public function test_audio_endpoint_supports_byte_range_requests(): void
    {
        Storage::fake('public');
        Storage::disk('public')->put('calls/audio/sample.mp3', str_repeat('a', 500));

        $user = User::factory()->create();
        $call = Call::factory()->create([
            'audio_path' => 'calls/audio/sample.mp3',
        ]);

        $this->actingAs($user)
            ->withHeaders(['Range' => 'bytes=0-99'])
            ->get("/api/calls/{$call->id}/audio")
            ->assertStatus(206)
            ->assertHeader('Accept-Ranges', 'bytes')
            ->assertHeader('Content-Range', 'bytes 0-99/500')
            ->assertHeader('Content-Length', '100');
    }
}
