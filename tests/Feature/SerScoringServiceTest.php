<?php

namespace Tests\Feature;

use App\Models\Call;
use App\Models\CallAnalysis;
use App\Services\AudioClipExtractionService;
use App\Services\SerScoringService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class SerScoringServiceTest extends TestCase
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

    public function test_score_call_parses_mocked_predict_response(): void
    {
        Storage::fake('public');
        Storage::disk('public')->put('calls/audio/sample.wav', 'fake-audio-bytes');

        $call = Call::factory()->create([
            'audio_path' => 'calls/audio/sample.wav',
            'audio_original_name' => 'sample.wav',
            'status' => Call::STATUS_COMPLETED,
        ]);

        Http::preventStrayRequests();
        Http::fake([
            'http://ser.test/predict' => Http::response([
                'courtesy_score' => 81.5,
                'rudeness_score' => 12.25,
                'emotional_tone_score' => 77.75,
                'model_version' => 'v1',
            ]),
        ]);

        $result = app(SerScoringService::class)->scoreCall($call);

        $this->assertNotNull($result);
        $this->assertSame(81.5, $result['courtesy_score']);
        $this->assertSame(12.25, $result['rudeness_score']);
        $this->assertSame(77.75, $result['emotional_tone_score']);
        $this->assertSame('v1', $result['model_version']);

        Http::assertSent(function (Request $request): bool {
            return $request->url() === 'http://ser.test/predict'
                && $request->isMultipart()
                && $request->hasFile('file');
        });
    }

    public function test_score_call_returns_null_when_service_is_unreachable(): void
    {
        Storage::fake('public');
        Storage::disk('public')->put('calls/audio/sample.wav', 'fake-audio-bytes');

        $call = Call::factory()->create([
            'audio_path' => 'calls/audio/sample.wav',
            'status' => Call::STATUS_COMPLETED,
        ]);

        Http::preventStrayRequests();
        Http::fake([
            'http://ser.test/predict' => Http::failedConnection(),
        ]);

        $result = app(SerScoringService::class)->scoreCall($call);

        $this->assertNull($result);
    }

    public function test_score_segments_returns_per_segment_scores_in_order(): void
    {
        Storage::fake('public');
        Storage::disk('public')->put('calls/audio/sample.wav', 'fake-audio-bytes');

        $call = Call::factory()->create([
            'audio_path' => 'calls/audio/sample.wav',
            'status' => Call::STATUS_COMPLETED,
        ]);

        CallAnalysis::query()->create([
            'call_id' => $call->id,
            'transcript' => 'One. Two.',
            'transcript_segments' => [
                [
                    'speaker' => 'operator',
                    'start' => 0.0,
                    'end' => 1.2,
                    'text' => 'One.',
                    'courtesy_score' => 90,
                    'rudeness_score' => 5,
                    'emotional_tone_score' => 88,
                ],
                [
                    'speaker' => 'musteri',
                    'start' => 1.5,
                    'end' => 1.7,
                    'text' => 'Hm.',
                    'courtesy_score' => 70,
                    'rudeness_score' => 10,
                    'emotional_tone_score' => 65,
                ],
                [
                    'speaker' => 'musteri',
                    'start' => 2.0,
                    'end' => 3.5,
                    'text' => 'Two.',
                    'courtesy_score' => 75,
                    'rudeness_score' => 8,
                    'emotional_tone_score' => 70,
                ],
            ],
        ]);

        $this->mock(AudioClipExtractionService::class, function ($mock): void {
            $mock->shouldReceive('extractSegmentClip')
                ->twice()
                ->andReturnUsing(function () {
                    $path = sys_get_temp_dir().DIRECTORY_SEPARATOR.'ser-seg-'.uniqid('', true).'.wav';
                    file_put_contents($path, 'clip-bytes');

                    return $path;
                });
        });

        Http::preventStrayRequests();
        Http::fake([
            'http://ser.test/predict' => Http::sequence()
                ->push([
                    'courtesy_score' => 81.0,
                    'rudeness_score' => 12.0,
                    'emotional_tone_score' => 77.0,
                    'model_version' => 'v1',
                ])
                ->push([
                    'courtesy_score' => 65.0,
                    'rudeness_score' => 18.0,
                    'emotional_tone_score' => 55.0,
                    'model_version' => 'v1',
                ]),
        ]);

        $results = app(SerScoringService::class)->scoreSegments($call->fresh(['analysis']));

        $this->assertCount(3, $results);
        $this->assertSame(81.0, $results[0]['courtesy_score']);
        $this->assertSame('v1', $results[0]['model_version']);
        $this->assertNull($results[1]);
        $this->assertSame(65.0, $results[2]['courtesy_score']);
        $this->assertSame(55.0, $results[2]['emotional_tone_score']);

        Http::assertSentCount(2);
    }

    public function test_score_segments_leaves_null_for_failed_segment_and_continues(): void
    {
        Storage::fake('public');
        Storage::disk('public')->put('calls/audio/sample.wav', 'fake-audio-bytes');

        $call = Call::factory()->create([
            'audio_path' => 'calls/audio/sample.wav',
            'status' => Call::STATUS_COMPLETED,
        ]);

        CallAnalysis::query()->create([
            'call_id' => $call->id,
            'transcript' => 'A. B.',
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
            ],
        ]);

        $this->mock(AudioClipExtractionService::class, function ($mock): void {
            $mock->shouldReceive('extractSegmentClip')
                ->twice()
                ->andReturnUsing(function () {
                    $path = sys_get_temp_dir().DIRECTORY_SEPARATOR.'ser-seg-'.uniqid('', true).'.wav';
                    file_put_contents($path, 'clip-bytes');

                    return $path;
                });
        });

        Http::preventStrayRequests();
        Http::fake([
            'http://ser.test/predict' => Http::sequence()
                ->push(['error' => 'fail'], 503)
                ->push([
                    'courtesy_score' => 72.0,
                    'rudeness_score' => 11.0,
                    'emotional_tone_score' => 68.0,
                    'model_version' => 'v3',
                ]),
        ]);

        $results = app(SerScoringService::class)->scoreSegments($call->fresh(['analysis']));

        $this->assertNull($results[0]);
        $this->assertSame(72.0, $results[1]['courtesy_score']);
        $this->assertSame('v3', $results[1]['model_version']);

        $aggregate = app(SerScoringService::class)->aggregateSegmentScores($results);

        $this->assertNotNull($aggregate);
        $this->assertSame(72.0, $aggregate['courtesy_score']);
        $this->assertSame('v3', $aggregate['model_version']);
    }
}
