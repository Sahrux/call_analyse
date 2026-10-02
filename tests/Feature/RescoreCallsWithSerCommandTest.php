<?php

namespace Tests\Feature;

use App\Models\Call;
use App\Models\CallAnalysis;
use App\Models\SerScore;
use App\Services\AudioClipExtractionService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class RescoreCallsWithSerCommandTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        config([
            'ser.service_url' => 'http://ser.test',
            'ser.timeout' => 30,
            'ser.connect_timeout' => 3,
        ]);
    }

    public function test_rescore_command_appends_ser_score_without_touching_existing_rows(): void
    {
        Storage::fake('public');
        Storage::disk('public')->put('calls/audio/sample.wav', 'fake-audio-bytes');

        $call = Call::factory()->create([
            'audio_path' => 'calls/audio/sample.wav',
            'audio_original_name' => 'sample.wav',
            'status' => Call::STATUS_COMPLETED,
        ]);

        CallAnalysis::query()->create([
            'call_id' => $call->id,
            'transcript' => 'Buyurun.',
            'transcript_segments' => [
                [
                    'speaker' => 'operator',
                    'start' => 0.0,
                    'end' => 1.5,
                    'text' => 'Buyurun.',
                    'courtesy_score' => 90,
                    'rudeness_score' => 5,
                    'emotional_tone_score' => 88,
                ],
            ],
        ]);

        $existing = SerScore::query()->create([
            'call_id' => $call->id,
            'model_version' => 'v1',
            'courtesy_score' => 50.0,
            'rudeness_score' => 10.0,
            'emotional_tone_score' => 60.0,
            'scored_at' => now()->subDay(),
        ]);

        $this->mock(AudioClipExtractionService::class, function ($mock): void {
            $mock->shouldReceive('extractSegmentClip')
                ->once()
                ->andReturnUsing(function () {
                    $path = sys_get_temp_dir().DIRECTORY_SEPARATOR.'ser-cmd-'.uniqid('', true).'.wav';
                    file_put_contents($path, 'clip-bytes');

                    return $path;
                });
        });

        Http::preventStrayRequests();
        Http::fake([
            'http://ser.test/health' => Http::response([
                'status' => 'ok',
                'model_version' => 'v2',
            ]),
            'http://ser.test/predict' => Http::response([
                'courtesy_score' => 88.0,
                'rudeness_score' => 7.5,
                'emotional_tone_score' => 91.0,
                'model_version' => 'v2',
            ]),
        ]);

        $exitCode = Artisan::call('calls:rescore-with-ser', ['--call-id' => $call->id]);

        $this->assertSame(0, $exitCode);
        $this->assertSame(2, SerScore::query()->where('call_id', $call->id)->count());

        $existing->refresh();
        $this->assertSame('v1', $existing->model_version);
        $this->assertSame(50.0, $existing->courtesy_score);

        $this->assertDatabaseHas('ser_scores', [
            'call_id' => $call->id,
            'model_version' => 'v2',
            'courtesy_score' => 88.0,
            'rudeness_score' => 7.5,
            'emotional_tone_score' => 91.0,
        ]);

        $segments = $call->fresh()->analysis->transcript_segments;
        $this->assertEqualsWithDelta(88.0, $segments[0]['ser_courtesy_score'], 0.001);
        $this->assertSame('v2', $segments[0]['ser_model_version']);
    }

    public function test_rescore_command_aborts_when_service_is_unhealthy(): void
    {
        $call = Call::factory()->create([
            'status' => Call::STATUS_COMPLETED,
        ]);

        Http::preventStrayRequests();
        Http::fake([
            'http://ser.test/health' => Http::failedConnection(),
        ]);

        $exitCode = Artisan::call('calls:rescore-with-ser', ['--call-id' => $call->id]);

        $this->assertSame(1, $exitCode);
        $this->assertSame(0, SerScore::query()->count());
    }
}
