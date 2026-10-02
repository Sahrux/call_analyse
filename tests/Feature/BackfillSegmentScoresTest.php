<?php

namespace Tests\Feature;

use App\Jobs\AnalyzeCallJob;
use App\Models\Call;
use App\Models\CallAnalysis;
use App\Models\Setting;
use App\Services\AiProviderResolver;
use App\Services\CallScoringService;
use App\Services\CallSegmentScoreBackfillService;
use App\Services\WhisperTranscriptionService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class BackfillSegmentScoresTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        config(['whisper.gemini_api_key' => 'test-gemini-key']);
    }

    public function test_analyze_call_job_saves_call_scores_without_segment_scores(): void
    {
        Setting::setValue('weight_courtesy', 25, 'scoring_params');
        Setting::setValue('weight_operator_interruption', 10, 'scoring_params');
        Setting::setValue('weight_customer_interruption', 10, 'scoring_params');
        Setting::setValue('weight_rudeness', 15, 'scoring_params');
        Setting::setValue('weight_emotion', 10, 'scoring_params');
        Setting::setValue('weight_operator_inappropriate', 7, 'scoring_params');
        Setting::setValue('weight_customer_inappropriate', 8, 'scoring_params');

        $call = Call::factory()->create([
            'language' => 'az',
            'status' => Call::STATUS_COMPLETED,
        ]);

        CallAnalysis::query()->create([
            'call_id' => $call->id,
            'transcript' => 'Buyurun. Salam.',
            'transcript_segments' => [
                ['speaker' => 'operator', 'start' => 0.0, 'end' => 1.0, 'text' => 'Buyurun.'],
                ['speaker' => 'musteri', 'start' => 1.5, 'end' => 2.5, 'text' => 'Salam.'],
            ],
        ]);

        Http::fake([
            'generativelanguage.googleapis.com/v1beta/interactions' => Http::sequence()
                ->push([
                    'steps' => [
                        [
                            'content' => [
                                [
                                    'type' => 'text',
                                    'text' => '{"courtesy_score":85,"rudeness_score":10,"emotional_tone_score":80}',
                                ],
                            ],
                        ],
                    ],
                ])
                ->push([
                    'steps' => [
                        [
                            'content' => [
                                [
                                    'type' => 'text',
                                    'text' => '[{"segment_index":0,"courtesy_score":92,"rudeness_score":3,"emotional_tone_score":90},{"segment_index":1,"courtesy_score":75,"rudeness_score":8,"emotional_tone_score":70}]',
                                ],
                            ],
                        ],
                    ],
                ]),
        ]);

        $this->partialMock(CallScoringService::class, function ($mock): void {
            $mock->shouldReceive('calculateInterruptionCounts')->once()->andReturn([
                'interruption_count' => 0,
                'operator_interruption_count' => 0,
                'customer_interruption_count' => 0,
            ]);
            $mock->shouldReceive('resolveInappropriateWordCounts')->once()->andReturn([
                'inappropriate_words_count' => 0,
                'operator_inappropriate_words_count' => null,
                'customer_inappropriate_words_count' => null,
            ]);
        });

        (new AnalyzeCallJob($call))->handle(
            app(CallScoringService::class),
            app(AiProviderResolver::class),
        );

        $analysis = CallAnalysis::query()->where('call_id', $call->id)->first();

        $this->assertNotNull($analysis);
        $this->assertSame(85, $analysis->courtesy_score);
        $this->assertSame(63, $analysis->total_score);
        $this->assertArrayNotHasKey('courtesy_score', $analysis->transcript_segments[0]);
        $this->assertSame('Buyurun.', $analysis->transcript_segments[0]['text']);
    }

    public function test_backfill_command_populates_missing_segment_scores(): void
    {
        $call = Call::factory()->create([
            'status' => Call::STATUS_COMPLETED,
        ]);

        CallAnalysis::query()->create([
            'call_id' => $call->id,
            'transcript' => 'Buyurun.',
            'transcript_segments' => [
                ['speaker' => 'operator', 'start' => 0.0, 'end' => 1.0, 'text' => 'Buyurun.'],
            ],
        ]);

        $this->mock(WhisperTranscriptionService::class, function ($mock): void {
            $mock->shouldReceive('applySegmentQualityScores')
                ->once()
                ->andReturn([
                    [
                        'speaker' => 'operator',
                        'start' => 0.0,
                        'end' => 1.0,
                        'text' => 'Buyurun.',
                        'courtesy_score' => 88,
                        'rudeness_score' => 4,
                        'emotional_tone_score' => 86,
                    ],
                ]);
        });

        $exitCode = Artisan::call('calls:backfill-segment-scores', [
            '--call' => $call->id,
        ]);

        $this->assertSame(0, $exitCode);

        $analysis = CallAnalysis::query()->where('call_id', $call->id)->first();

        $this->assertSame(88, $analysis->transcript_segments[0]['courtesy_score']);
        $this->assertSame(86, $analysis->transcript_segments[0]['emotional_tone_score']);
    }

    public function test_backfill_service_skips_calls_that_already_have_segment_scores(): void
    {
        $call = Call::factory()->create([
            'status' => Call::STATUS_COMPLETED,
        ]);

        CallAnalysis::query()->create([
            'call_id' => $call->id,
            'transcript' => 'Buyurun.',
            'transcript_segments' => [
                [
                    'speaker' => 'operator',
                    'start' => 0.0,
                    'end' => 1.0,
                    'text' => 'Buyurun.',
                    'courtesy_score' => 90,
                    'rudeness_score' => 5,
                    'emotional_tone_score' => 88,
                ],
            ],
        ]);

        $calls = app(CallSegmentScoreBackfillService::class)->callsNeedingSegmentScores();

        $this->assertCount(0, $calls);
    }
}
