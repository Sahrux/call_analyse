<?php

namespace Tests\Feature;

use App\Jobs\AnalyzeCallJob;
use App\Models\Call;
use App\Models\CallAnalysis;
use App\Models\Setting;
use App\Services\AiProviderResolver;
use App\Services\CallScoringService;
use App\Services\WhisperTranscriptionService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class AnalyzeCallJobTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        Setting::setValue('weight_courtesy', 25, 'scoring_params');
        Setting::setValue('weight_operator_interruption', 10, 'scoring_params');
        Setting::setValue('weight_customer_interruption', 10, 'scoring_params');
        Setting::setValue('weight_rudeness', 15, 'scoring_params');
        Setting::setValue('weight_emotion', 10, 'scoring_params');
        Setting::setValue('weight_operator_inappropriate', 7, 'scoring_params');
        Setting::setValue('weight_customer_inappropriate', 8, 'scoring_params');
    }

    public function test_analyze_call_job_persists_scores_and_total_score(): void
    {
        $call = Call::factory()->create([
            'language' => 'az',
            'status' => Call::STATUS_COMPLETED,
        ]);

        CallAnalysis::query()->create([
            'call_id' => $call->id,
            'transcript' => 'Salam, necəsiniz?',
            'transcript_segments' => [
                ['speaker' => 'spk_1', 'start' => 0.0, 'end' => 5.0, 'text' => 'Salam'],
                ['speaker' => 'spk_2', 'start' => 4.0, 'end' => 6.0, 'text' => 'necəsiniz?'],
            ],
        ]);

        $this->mockScoringCounts(
            interruptionCount: 1,
            inappropriateWordsCount: 2,
            operatorInterruptionCount: 0,
            customerInterruptionCount: 1,
        );

        $this->mock(WhisperTranscriptionService::class, function ($mock): void {
            $mock->shouldReceive('analyzeCallQuality')
                ->once()
                ->with('Salam, necəsiniz?')
                ->andReturn([
                    'courtesy_score' => 80,
                    'rudeness_score' => 20,
                    'emotional_tone_score' => 75,
                ]);

            $mock->shouldNotReceive('applySegmentQualityScores');
        });

        (new AnalyzeCallJob($call))->handle(
            app(CallScoringService::class),
            app(AiProviderResolver::class),
        );

        $analysis = CallAnalysis::query()->where('call_id', $call->id)->first();

        $this->assertNotNull($analysis);
        $this->assertSame(80, $analysis->courtesy_score);
        $this->assertSame(20, $analysis->rudeness_score);
        $this->assertSame(75, $analysis->emotional_tone_score);
        $this->assertSame(1, $analysis->interruption_count);
        $this->assertSame(0, $analysis->operator_interruption_count);
        $this->assertSame(1, $analysis->customer_interruption_count);
        $this->assertSame(2, $analysis->inappropriate_words_count);
        $this->assertNull($analysis->operator_inappropriate_words_count);
        $this->assertNull($analysis->customer_inappropriate_words_count);
        $this->assertSame(38, $analysis->total_score);
        $this->assertNotNull($analysis->analyzed_at);
    }

    public function test_analyze_call_job_creates_analysis_record_when_missing(): void
    {
        $call = Call::factory()->create([
            'language' => 'en',
            'status' => Call::STATUS_COMPLETED,
        ]);

        CallAnalysis::query()->create([
            'call_id' => $call->id,
            'transcript' => 'Thank you for calling.',
            'transcript_segments' => [],
        ]);

        $this->mockScoringCounts(interruptionCount: 0, inappropriateWordsCount: 0);

        $this->mock(WhisperTranscriptionService::class, function ($mock): void {
            $mock->shouldReceive('analyzeCallQuality')
                ->once()
                ->andReturn([
                    'courtesy_score' => 90,
                    'rudeness_score' => 5,
                    'emotional_tone_score' => 85,
                ]);
        });

        (new AnalyzeCallJob($call))->handle(
            app(CallScoringService::class),
            app(AiProviderResolver::class),
        );

        $this->assertDatabaseHas('call_analyses', [
            'call_id' => $call->id,
            'courtesy_score' => 90,
            'total_score' => 67,
        ]);
    }

    public function test_analyze_call_job_clamps_total_score_between_zero_and_one_hundred(): void
    {
        $call = Call::factory()->create([
            'language' => 'en',
            'status' => Call::STATUS_COMPLETED,
        ]);

        CallAnalysis::query()->create([
            'call_id' => $call->id,
            'transcript' => 'Very rude call.',
            'transcript_segments' => [],
        ]);

        foreach (CallScoringService::WEIGHT_DEFAULTS as $key => $value) {
            Setting::setValue($key, 20, 'scoring_params');
        }

        $this->mockScoringCounts(
            interruptionCount: 50,
            inappropriateWordsCount: 50,
            operatorInterruptionCount: 30,
            customerInterruptionCount: 20,
        );

        $this->mock(WhisperTranscriptionService::class, function ($mock): void {
            $mock->shouldReceive('analyzeCallQuality')
                ->once()
                ->andReturn([
                    'courtesy_score' => 0,
                    'rudeness_score' => 100,
                    'emotional_tone_score' => 100,
                ]);
        });

        (new AnalyzeCallJob($call))->handle(
            app(CallScoringService::class),
            app(AiProviderResolver::class),
        );

        $this->assertSame(0, CallAnalysis::query()->where('call_id', $call->id)->value('total_score'));
    }

    public function test_analyze_call_job_skips_when_transcript_is_missing(): void
    {
        $call = Call::factory()->create([
            'language' => 'az',
            'status' => Call::STATUS_COMPLETED,
        ]);

        $this->mock(CallScoringService::class, function ($mock): void {
            $mock->shouldNotReceive('calculateInterruptionCounts');
            $mock->shouldNotReceive('resolveInappropriateWordCounts');
            $mock->shouldNotReceive('countInappropriateWords');
            $mock->shouldNotReceive('calculateTotalScore');
            $mock->shouldNotReceive('calculateOverall');
        });

        $this->mock(WhisperTranscriptionService::class, function ($mock): void {
            $mock->shouldNotReceive('analyzeCallQuality');
            $mock->shouldNotReceive('applySegmentQualityScores');
        });

        (new AnalyzeCallJob($call))->handle(
            app(CallScoringService::class),
            app(AiProviderResolver::class),
        );
    }

    public function test_analyze_call_job_does_not_apply_segment_quality_scores(): void
    {
        $call = Call::factory()->create([
            'language' => 'az',
            'status' => Call::STATUS_COMPLETED,
        ]);

        $originalSegments = [
            ['speaker' => 'operator', 'start' => 0.0, 'end' => 1.0, 'text' => 'Buyurun.'],
            ['speaker' => 'musteri', 'start' => 1.5, 'end' => 2.5, 'text' => 'Salam.'],
        ];

        CallAnalysis::query()->create([
            'call_id' => $call->id,
            'transcript' => 'Buyurun. Salam.',
            'transcript_segments' => $originalSegments,
        ]);

        $this->mockScoringCounts(interruptionCount: 0, inappropriateWordsCount: 0);

        $this->mock(WhisperTranscriptionService::class, function ($mock): void {
            $mock->shouldReceive('analyzeCallQuality')->once()->andReturn([
                'courtesy_score' => 85,
                'rudeness_score' => 10,
                'emotional_tone_score' => 80,
            ]);

            $mock->shouldNotReceive('applySegmentQualityScores');
        });

        (new AnalyzeCallJob($call))->handle(
            app(CallScoringService::class),
            app(AiProviderResolver::class),
        );

        $analysis = CallAnalysis::query()->where('call_id', $call->id)->first();

        $this->assertNotNull($analysis);
        $this->assertCount(2, $analysis->transcript_segments);
        $this->assertSame('operator', $analysis->transcript_segments[0]['speaker']);
        $this->assertSame('Buyurun.', $analysis->transcript_segments[0]['text']);
        $this->assertArrayNotHasKey('courtesy_score', $analysis->transcript_segments[0]);
        $this->assertArrayNotHasKey('rudeness_score', $analysis->transcript_segments[0]);
        $this->assertArrayNotHasKey('emotional_tone_score', $analysis->transcript_segments[0]);
        $this->assertSame(85, $analysis->courtesy_score);
        $this->assertSame(63, $analysis->total_score);
    }

    public function test_total_score_deducts_each_weight_when_weights_do_not_sum_to_one_hundred(): void
    {
        Setting::setValue('weight_courtesy', 70, 'scoring_params');
        Setting::setValue('weight_operator_interruption', 4, 'scoring_params');
        Setting::setValue('weight_customer_interruption', 4, 'scoring_params');
        Setting::setValue('weight_rudeness', 4, 'scoring_params');
        Setting::setValue('weight_emotion', 4, 'scoring_params');
        Setting::setValue('weight_operator_inappropriate', 2, 'scoring_params');
        Setting::setValue('weight_customer_inappropriate', 2, 'scoring_params');

        $call = Call::factory()->create([
            'language' => 'az',
            'status' => Call::STATUS_COMPLETED,
        ]);

        CallAnalysis::query()->create([
            'call_id' => $call->id,
            'transcript' => 'Buyurun.',
            'transcript_segments' => [],
        ]);

        $this->mockScoringCounts(
            interruptionCount: 10,
            inappropriateWordsCount: 0,
            operatorInterruptionCount: 10,
        );

        $this->mock(WhisperTranscriptionService::class, function ($mock): void {
            $mock->shouldReceive('analyzeCallQuality')->once()->andReturn([
                'courtesy_score' => 100,
                'rudeness_score' => 0,
                'emotional_tone_score' => 100,
            ]);
        });

        (new AnalyzeCallJob($call))->handle(
            app(CallScoringService::class),
            app(AiProviderResolver::class),
        );

        $this->assertSame(61, CallAnalysis::query()->where('call_id', $call->id)->value('total_score'));
    }

    public function test_total_score_is_zero_when_every_quality_weight_is_zero(): void
    {
        Setting::setValue('weight_courtesy', 0, 'scoring_params');
        Setting::setValue('weight_operator_interruption', 0, 'scoring_params');
        Setting::setValue('weight_customer_interruption', 0, 'scoring_params');
        Setting::setValue('weight_rudeness', 0, 'scoring_params');
        Setting::setValue('weight_emotion', 0, 'scoring_params');
        Setting::setValue('weight_operator_inappropriate', 0, 'scoring_params');
        Setting::setValue('weight_customer_inappropriate', 0, 'scoring_params');

        $call = Call::factory()->create([
            'language' => 'az',
            'status' => Call::STATUS_COMPLETED,
        ]);

        CallAnalysis::query()->create([
            'call_id' => $call->id,
            'transcript' => 'Buyurun.',
            'transcript_segments' => [],
        ]);

        $this->mockScoringCounts(interruptionCount: 0, inappropriateWordsCount: 0);

        $this->mock(WhisperTranscriptionService::class, function ($mock): void {
            $mock->shouldReceive('analyzeCallQuality')->once()->andReturn([
                'courtesy_score' => 100,
                'rudeness_score' => 0,
                'emotional_tone_score' => 100,
            ]);
        });

        (new AnalyzeCallJob($call))->handle(
            app(CallScoringService::class),
            app(AiProviderResolver::class),
        );

        $this->assertSame(0, CallAnalysis::query()->where('call_id', $call->id)->value('total_score'));
    }

    public function test_total_score_includes_interruption_and_inappropriate_parameters(): void
    {
        $call = Call::factory()->create([
            'language' => 'az',
            'status' => Call::STATUS_COMPLETED,
        ]);

        CallAnalysis::query()->create([
            'call_id' => $call->id,
            'transcript' => 'Buyurun.',
            'transcript_segments' => [],
        ]);

        $this->mockScoringCounts(interruptionCount: 0, inappropriateWordsCount: 0);

        $this->mock(WhisperTranscriptionService::class, function ($mock): void {
            $mock->shouldReceive('analyzeCallQuality')->once()->andReturn([
                'courtesy_score' => 0,
                'rudeness_score' => 100,
                'emotional_tone_score' => 0,
            ]);
        });

        (new AnalyzeCallJob($call))->handle(
            app(CallScoringService::class),
            app(AiProviderResolver::class),
        );

        $this->assertSame(9, CallAnalysis::query()->where('call_id', $call->id)->value('total_score'));
    }

    public function test_total_score_compounds_ten_operator_interruptions(): void
    {
        $call = Call::factory()->create([
            'language' => 'az',
            'status' => Call::STATUS_COMPLETED,
        ]);

        CallAnalysis::query()->create([
            'call_id' => $call->id,
            'transcript' => 'Buyurun.',
            'transcript_segments' => [],
        ]);

        $this->mockScoringCounts(
            interruptionCount: 10,
            inappropriateWordsCount: 0,
            operatorInterruptionCount: 10,
        );

        $this->mock(WhisperTranscriptionService::class, function ($mock): void {
            $mock->shouldReceive('analyzeCallQuality')->once()->andReturn([
                'courtesy_score' => 0,
                'rudeness_score' => 100,
                'emotional_tone_score' => 0,
            ]);
        });

        (new AnalyzeCallJob($call))->handle(
            app(CallScoringService::class),
            app(AiProviderResolver::class),
        );

        $this->assertSame(3, CallAnalysis::query()->where('call_id', $call->id)->value('total_score'));
    }

    public function test_analyze_call_job_splits_gemini_inappropriate_expressions_by_speaker(): void
    {
        $call = Call::factory()->create([
            'language' => 'az',
            'status' => Call::STATUS_COMPLETED,
        ]);

        CallAnalysis::query()->create([
            'call_id' => $call->id,
            'transcript' => 'Axmaq. Buyurun.',
            'transcript_segments' => [],
        ]);

        $this->mock(WhisperTranscriptionService::class, function ($mock): void {
            $mock->shouldReceive('analyzeCallQuality')
                ->once()
                ->andReturn([
                    'courtesy_score' => 80,
                    'rudeness_score' => 20,
                    'emotional_tone_score' => 75,
                    'inappropriate_expressions' => [
                        ['speaker' => 'operator', 'text' => 'axmaq'],
                        ['speaker' => 'musteri', 'text' => 'lanət'],
                        ['speaker' => 'musteri', 'text' => 'debil'],
                    ],
                ]);
        });

        (new AnalyzeCallJob($call))->handle(
            app(CallScoringService::class),
            app(AiProviderResolver::class),
        );

        $analysis = CallAnalysis::query()->where('call_id', $call->id)->first();

        $this->assertNotNull($analysis);
        $this->assertSame(1, $analysis->operator_inappropriate_words_count);
        $this->assertSame(2, $analysis->customer_inappropriate_words_count);
        $this->assertSame(3, $analysis->inappropriate_words_count);
        $this->assertSame([
            ['speaker' => 'operator', 'text' => 'axmaq'],
            ['speaker' => 'musteri', 'text' => 'lanət'],
            ['speaker' => 'musteri', 'text' => 'debil'],
        ], $analysis->inappropriate_expressions);
    }

    public function test_analyze_call_job_falls_back_to_dictionary_when_gemini_expressions_missing(): void
    {
        $call = Call::factory()->create([
            'language' => 'az',
            'status' => Call::STATUS_COMPLETED,
        ]);

        CallAnalysis::query()->create([
            'call_id' => $call->id,
            'transcript' => 'sən axmaq adamsan',
            'transcript_segments' => [],
        ]);

        $this->mock(WhisperTranscriptionService::class, function ($mock): void {
            $mock->shouldReceive('analyzeCallQuality')
                ->once()
                ->andReturn([
                    'courtesy_score' => 80,
                    'rudeness_score' => 20,
                    'emotional_tone_score' => 75,
                    'inappropriate_expressions' => null,
                ]);
        });

        (new AnalyzeCallJob($call))->handle(
            app(CallScoringService::class),
            app(AiProviderResolver::class),
        );

        $analysis = CallAnalysis::query()->where('call_id', $call->id)->first();

        $this->assertNotNull($analysis);
        $this->assertSame(1, $analysis->inappropriate_words_count);
        $this->assertNull($analysis->operator_inappropriate_words_count);
        $this->assertNull($analysis->customer_inappropriate_words_count);
        $this->assertNull($analysis->inappropriate_expressions);
    }

    private function mockScoringCounts(
        int $interruptionCount,
        int $inappropriateWordsCount,
        int $operatorInterruptionCount = 0,
        int $customerInterruptionCount = 0,
        ?int $operatorInappropriateWordsCount = null,
        ?int $customerInappropriateWordsCount = null,
    ): void {
        $this->partialMock(CallScoringService::class, function ($mock) use (
            $interruptionCount,
            $inappropriateWordsCount,
            $operatorInterruptionCount,
            $customerInterruptionCount,
            $operatorInappropriateWordsCount,
            $customerInappropriateWordsCount,
        ): void {
            $mock->shouldReceive('calculateInterruptionCounts')
                ->once()
                ->andReturn([
                    'interruption_count' => $interruptionCount,
                    'operator_interruption_count' => $operatorInterruptionCount,
                    'customer_interruption_count' => $customerInterruptionCount,
                ]);

            $mock->shouldReceive('resolveInappropriateWordCounts')
                ->once()
                ->andReturn([
                    'inappropriate_words_count' => $inappropriateWordsCount,
                    'operator_inappropriate_words_count' => $operatorInappropriateWordsCount,
                    'customer_inappropriate_words_count' => $customerInappropriateWordsCount,
                ]);
        });
    }
}
