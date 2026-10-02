<?php

namespace Tests\Feature;

use App\Models\Call;
use App\Models\CallAnalysis;
use App\Models\Setting;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Tests\TestCase;

class FakeScoreUnscoredCallsCommandTest extends TestCase
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

    public function test_case_a_writes_call_and_segment_scores_and_marks_completed(): void
    {
        $call = Call::factory()->create([
            'language' => 'az',
            'status' => Call::STATUS_FAILED,
            'error_message' => 'Gemini rate limit',
        ]);

        CallAnalysis::query()->create([
            'call_id' => $call->id,
            'transcript' => 'Buyurun. Salam.',
            'transcript_segments' => [
                ['speaker' => 'operator', 'start' => 0.0, 'end' => 1.0, 'text' => 'Buyurun.'],
                ['speaker' => 'musteri', 'start' => 1.5, 'end' => 2.5, 'text' => 'Salam.'],
            ],
            'total_score' => null,
        ]);

        $exitCode = Artisan::call('calls:fake-score');

        $this->assertSame(0, $exitCode);

        $call->refresh();
        $analysis = $call->analysis()->first();

        $this->assertSame(Call::STATUS_COMPLETED, $call->status);
        $this->assertNull($call->error_message);
        $this->assertTrue($analysis->is_fake_scored);
        $this->assertNotNull($analysis->total_score);
        $this->assertGreaterThanOrEqual(0, $analysis->total_score);
        $this->assertLessThanOrEqual(100, $analysis->total_score);
        $this->assertNotNull($analysis->courtesy_score);
        $this->assertNotNull($analysis->rudeness_score);
        $this->assertNotNull($analysis->emotional_tone_score);
        $this->assertNotNull($analysis->interruption_count);
        $this->assertNotNull($analysis->operator_interruption_count);
        $this->assertNotNull($analysis->customer_interruption_count);
        $this->assertNull($analysis->operator_inappropriate_words_count);
        $this->assertNull($analysis->customer_inappropriate_words_count);
        $this->assertNotNull($analysis->inappropriate_words_count);
        $this->assertSame(CallAnalysis::FAKE_SCORE_SUMMARY, $analysis->ai_summary);
        $this->assertNotNull($analysis->analyzed_at);
        $this->assertArrayHasKey('courtesy_score', $analysis->transcript_segments[0]);
        $this->assertTrue($analysis->transcript_segments[0]['is_fake_scored']);
        $this->assertArrayHasKey('emotional_tone_score', $analysis->transcript_segments[1]);
        $this->assertTrue($analysis->transcript_segments[1]['is_fake_scored']);
        $this->assertStringContainsString('Case A (full fake score): 1', Artisan::output());
    }

    public function test_case_b_fills_only_missing_segment_scores_and_keeps_call_total(): void
    {
        $call = Call::factory()->create([
            'status' => Call::STATUS_COMPLETED,
        ]);

        CallAnalysis::query()->create([
            'call_id' => $call->id,
            'transcript' => 'Buyurun. Salam.',
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
                [
                    'speaker' => 'musteri',
                    'start' => 1.5,
                    'end' => 2.5,
                    'text' => 'Salam.',
                ],
            ],
            'courtesy_score' => 85,
            'rudeness_score' => 10,
            'emotional_tone_score' => 80,
            'total_score' => 88,
            'interruption_count' => 0,
            'operator_interruption_count' => 0,
            'customer_interruption_count' => 0,
            'inappropriate_words_count' => 0,
            'ai_summary' => 'Real Gemini summary',
        ]);

        $exitCode = Artisan::call('calls:fake-score');

        $this->assertSame(0, $exitCode);

        $analysis = CallAnalysis::query()->where('call_id', $call->id)->first();

        $this->assertSame(88, $analysis->total_score);
        $this->assertSame(85, $analysis->courtesy_score);
        $this->assertTrue($analysis->is_fake_scored);
        $this->assertSame('Real Gemini summary', $analysis->ai_summary);
        $this->assertSame(90, $analysis->transcript_segments[0]['courtesy_score']);
        $this->assertArrayNotHasKey('is_fake_scored', $analysis->transcript_segments[0]);
        $this->assertArrayHasKey('courtesy_score', $analysis->transcript_segments[1]);
        $this->assertTrue($analysis->transcript_segments[1]['is_fake_scored']);
        $this->assertArrayHasKey('rudeness_score', $analysis->transcript_segments[1]);
        $this->assertArrayHasKey('emotional_tone_score', $analysis->transcript_segments[1]);
        $this->assertStringContainsString('Case B (segments only):   1', Artisan::output());
    }

    public function test_dry_run_lists_calls_without_writing(): void
    {
        $call = Call::factory()->create([
            'status' => Call::STATUS_FAILED,
            'error_message' => 'Gemini rate limit',
        ]);

        CallAnalysis::query()->create([
            'call_id' => $call->id,
            'transcript' => 'Buyurun.',
            'transcript_segments' => [
                ['speaker' => 'operator', 'start' => 0.0, 'end' => 1.0, 'text' => 'Buyurun.'],
            ],
            'total_score' => null,
        ]);

        $exitCode = Artisan::call('calls:fake-score', ['--dry-run' => true]);

        $this->assertSame(0, $exitCode);

        $call->refresh();
        $analysis = $call->analysis()->first();

        $this->assertSame(Call::STATUS_FAILED, $call->status);
        $this->assertSame('Gemini rate limit', $call->error_message);
        $this->assertNull($analysis->total_score);
        $this->assertStringContainsString('[Case A] Call #'.$call->id, Artisan::output());
    }

    public function test_case_a_without_segments_scores_call_level_only(): void
    {
        $call = Call::factory()->create([
            'status' => Call::STATUS_FAILED,
        ]);

        CallAnalysis::query()->create([
            'call_id' => $call->id,
            'transcript' => 'Buyurun.',
            'transcript_segments' => [],
            'total_score' => null,
        ]);

        $exitCode = Artisan::call('calls:fake-score');

        $this->assertSame(0, $exitCode);

        $analysis = CallAnalysis::query()->where('call_id', $call->id)->first();

        $this->assertNotNull($analysis->total_score);
        $this->assertSame([], $analysis->transcript_segments);
        $this->assertStringContainsString('no transcript segments', Artisan::output());
    }

    public function test_limit_caps_number_of_calls_processed(): void
    {
        foreach (range(1, 3) as $i) {
            $call = Call::factory()->create([
                'status' => Call::STATUS_FAILED,
            ]);

            CallAnalysis::query()->create([
                'call_id' => $call->id,
                'transcript' => "Call {$i}",
                'transcript_segments' => [
                    ['speaker' => 'operator', 'start' => 0.0, 'end' => 1.0, 'text' => 'Hi'],
                ],
                'total_score' => null,
            ]);
        }

        $exitCode = Artisan::call('calls:fake-score', ['--limit' => 2]);

        $this->assertSame(0, $exitCode);
        $this->assertSame(2, CallAnalysis::query()->whereNotNull('total_score')->count());
        $this->assertSame(1, CallAnalysis::query()->whereNull('total_score')->count());
    }

    public function test_skips_calls_that_already_have_full_scores(): void
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
            'courtesy_score' => 90,
            'total_score' => 92,
            'ai_summary' => 'Already scored',
        ]);

        $exitCode = Artisan::call('calls:fake-score');

        $this->assertSame(0, $exitCode);

        $analysis = CallAnalysis::query()->where('call_id', $call->id)->first();

        $this->assertSame(92, $analysis->total_score);
        $this->assertSame('Already scored', $analysis->ai_summary);
        $output = Artisan::output();
        $this->assertStringContainsString('Case A (full fake score): 0', $output);
        $this->assertStringContainsString('Case B (segments only):', $output);
        $this->assertMatchesRegularExpression('/Case B \(segments only\):\s+0/', $output);
    }
}
