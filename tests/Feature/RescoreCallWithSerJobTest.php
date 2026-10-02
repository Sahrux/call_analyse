<?php

namespace Tests\Feature;

use App\Jobs\RescoreCallWithSerJob;
use App\Models\Call;
use App\Models\CallAnalysis;
use App\Models\SerScore;
use App\Services\AudioClipExtractionService;
use App\Services\SerScoringService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class RescoreCallWithSerJobTest extends TestCase
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

    public function test_job_saves_per_segment_ser_fields_and_aggregate_ser_score_row(): void
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
            'transcript' => 'Buyurun. Salam.',
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
                [
                    'speaker' => 'musteri',
                    'start' => 2.0,
                    'end' => 3.5,
                    'text' => 'Salam.',
                    'courtesy_score' => 70,
                    'rudeness_score' => 10,
                    'emotional_tone_score' => 65,
                ],
            ],
        ]);

        $this->mock(AudioClipExtractionService::class, function ($mock): void {
            $mock->shouldReceive('extractSegmentClip')
                ->twice()
                ->andReturnUsing(function () {
                    $path = sys_get_temp_dir().DIRECTORY_SEPARATOR.'ser-test-'.uniqid('', true).'.wav';
                    file_put_contents($path, 'clip-bytes');

                    return $path;
                });
        });

        Http::preventStrayRequests();
        Http::fake([
            'http://ser.test/predict' => Http::sequence()
                ->push([
                    'courtesy_score' => 80.0,
                    'rudeness_score' => 10.0,
                    'emotional_tone_score' => 70.0,
                    'model_version' => 'v2',
                ])
                ->push([
                    'courtesy_score' => 60.0,
                    'rudeness_score' => 20.0,
                    'emotional_tone_score' => 50.0,
                    'model_version' => 'v2',
                ]),
        ]);

        (new RescoreCallWithSerJob($call))->handle(app(SerScoringService::class));

        $call->refresh()->load('analysis');
        $segments = $call->analysis->transcript_segments;

        $this->assertEqualsWithDelta(80.0, $segments[0]['ser_courtesy_score'], 0.001);
        $this->assertEqualsWithDelta(10.0, $segments[0]['ser_rudeness_score'], 0.001);
        $this->assertEqualsWithDelta(70.0, $segments[0]['ser_emotional_tone_score'], 0.001);
        $this->assertSame('v2', $segments[0]['ser_model_version']);

        $this->assertEqualsWithDelta(60.0, $segments[1]['ser_courtesy_score'], 0.001);
        $this->assertEqualsWithDelta(20.0, $segments[1]['ser_rudeness_score'], 0.001);
        $this->assertEqualsWithDelta(50.0, $segments[1]['ser_emotional_tone_score'], 0.001);
        $this->assertSame('v2', $segments[1]['ser_model_version']);

        $this->assertSame(1, SerScore::query()->where('call_id', $call->id)->count());
        $this->assertDatabaseHas('ser_scores', [
            'call_id' => $call->id,
            'model_version' => 'v2',
            'courtesy_score' => 70.0,
            'rudeness_score' => 15.0,
            'emotional_tone_score' => 60.0,
        ]);
    }

    public function test_job_continues_when_one_segment_fails_to_score(): void
    {
        Storage::fake('public');
        Storage::disk('public')->put('calls/audio/sample.wav', 'fake-audio-bytes');

        $call = Call::factory()->create([
            'audio_path' => 'calls/audio/sample.wav',
            'status' => Call::STATUS_COMPLETED,
        ]);

        CallAnalysis::query()->create([
            'call_id' => $call->id,
            'transcript' => 'A. B. C.',
            'transcript_segments' => [
                [
                    'speaker' => 'operator',
                    'start' => 0.0,
                    'end' => 1.0,
                    'text' => 'A.',
                    'courtesy_score' => 90,
                    'rudeness_score' => 5,
                    'emotional_tone_score' => 88,
                ],
                [
                    'speaker' => 'musteri',
                    'start' => 1.5,
                    'end' => 2.5,
                    'text' => 'B.',
                    'courtesy_score' => 70,
                    'rudeness_score' => 10,
                    'emotional_tone_score' => 65,
                ],
                [
                    'speaker' => 'operator',
                    'start' => 3.0,
                    'end' => 4.0,
                    'text' => 'C.',
                    'courtesy_score' => 85,
                    'rudeness_score' => 8,
                    'emotional_tone_score' => 80,
                ],
            ],
        ]);

        $this->mock(AudioClipExtractionService::class, function ($mock): void {
            $mock->shouldReceive('extractSegmentClip')
                ->times(3)
                ->andReturnUsing(function () {
                    $path = sys_get_temp_dir().DIRECTORY_SEPARATOR.'ser-test-'.uniqid('', true).'.wav';
                    file_put_contents($path, 'clip-bytes');

                    return $path;
                });
        });

        Http::preventStrayRequests();
        Http::fake([
            'http://ser.test/predict' => Http::sequence()
                ->push([
                    'courtesy_score' => 90.0,
                    'rudeness_score' => 5.0,
                    'emotional_tone_score' => 85.0,
                    'model_version' => 'v1',
                ])
                ->push(['error' => 'boom'], 500)
                ->push([
                    'courtesy_score' => 70.0,
                    'rudeness_score' => 15.0,
                    'emotional_tone_score' => 75.0,
                    'model_version' => 'v1',
                ]),
        ]);

        (new RescoreCallWithSerJob($call))->handle(app(SerScoringService::class));

        $segments = $call->fresh()->analysis->transcript_segments;

        $this->assertEqualsWithDelta(90.0, $segments[0]['ser_courtesy_score'], 0.001);
        $this->assertNull($segments[1]['ser_courtesy_score']);
        $this->assertNull($segments[1]['ser_model_version']);
        $this->assertEqualsWithDelta(70.0, $segments[2]['ser_courtesy_score'], 0.001);

        $this->assertDatabaseHas('ser_scores', [
            'call_id' => $call->id,
            'model_version' => 'v1',
            'courtesy_score' => 80.0,
            'rudeness_score' => 10.0,
            'emotional_tone_score' => 80.0,
        ]);
    }
}
