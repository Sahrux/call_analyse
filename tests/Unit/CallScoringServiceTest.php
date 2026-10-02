<?php

namespace Tests\Unit;

use App\Models\CallAnalysis;
use App\Models\SerScore;
use App\Models\Setting;
use App\Services\CallScoringService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class CallScoringServiceTest extends TestCase
{
    use RefreshDatabase;

    public function test_count_inappropriate_words_returns_zero_for_unknown_language(): void
    {
        $service = new CallScoringService;

        $this->assertSame(0, $service->countInappropriateWords('fuck you', 'de'));
    }

    public function test_count_inappropriate_words_is_case_insensitive(): void
    {
        $service = new CallScoringService;

        $this->assertSame(1, $service->countInappropriateWords('You are an IDIOT', 'en'));
    }

    public function test_count_inappropriate_words_counts_repeated_matches(): void
    {
        $service = new CallScoringService;

        $this->assertSame(2, $service->countInappropriateWords('idiot, you idiot', 'en'));
    }

    public function test_count_inappropriate_words_uses_word_boundaries(): void
    {
        $service = new CallScoringService;

        $this->assertSame(0, $service->countInappropriateWords('assassin', 'en'));
        $this->assertSame(1, $service->countInappropriateWords('you are an ass', 'en'));
    }

    public function test_count_inappropriate_words_matches_phrases_with_spaces(): void
    {
        $service = new CallScoringService;

        $this->assertSame(1, $service->countInappropriateWords('just shut   up already', 'en'));
    }

    public function test_count_inappropriate_words_matches_azerbaijani_characters(): void
    {
        $service = new CallScoringService;

        $this->assertSame(1, $service->countInappropriateWords('sən axmaq adamsan', 'az'));
        $this->assertSame(1, $service->countInappropriateWords('ədəbsiz davranış', 'az'));
    }

    public function test_count_inappropriate_words_matches_cyrillic_characters(): void
    {
        $service = new CallScoringService;

        $this->assertSame(1, $service->countInappropriateWords('он идиот', 'ru'));
        $this->assertSame(1, $service->countInappropriateWords('это пиздец', 'ru'));
    }

    public function test_count_inappropriate_words_sums_matches_from_multiple_dictionary_entries(): void
    {
        $service = new CallScoringService;

        $this->assertSame(3, $service->countInappropriateWords('fuck you, moron', 'en'));
    }

    public function test_resolve_inappropriate_word_counts_splits_gemini_expressions_by_speaker(): void
    {
        $service = new CallScoringService;

        $counts = $service->resolveInappropriateWordCounts([
            ['speaker' => 'operator', 'text' => 'axmaq'],
            ['speaker' => 'musteri', 'text' => 'lanət'],
            ['speaker' => 'customer', 'text' => 'debil'],
        ], 'sən axmaq adamsan', 'az');

        $this->assertSame(3, $counts['inappropriate_words_count']);
        $this->assertSame(1, $counts['operator_inappropriate_words_count']);
        $this->assertSame(2, $counts['customer_inappropriate_words_count']);
    }

    public function test_resolve_inappropriate_word_counts_falls_back_to_dictionary_without_speaker_split(): void
    {
        $service = new CallScoringService;

        $counts = $service->resolveInappropriateWordCounts(null, 'sən axmaq adamsan', 'az');

        $this->assertSame(1, $counts['inappropriate_words_count']);
        $this->assertNull($counts['operator_inappropriate_words_count']);
        $this->assertNull($counts['customer_inappropriate_words_count']);
    }

    public function test_resolve_inappropriate_word_counts_treats_empty_gemini_array_as_zero_split(): void
    {
        $service = new CallScoringService;

        $counts = $service->resolveInappropriateWordCounts([], 'sən axmaq adamsan', 'az');

        $this->assertSame(0, $counts['inappropriate_words_count']);
        $this->assertSame(0, $counts['operator_inappropriate_words_count']);
        $this->assertSame(0, $counts['customer_inappropriate_words_count']);
    }

    public function test_inappropriate_scores_from_counts_use_combined_total_when_speaker_split_is_missing(): void
    {
        $service = new CallScoringService;

        $scores = $service->inappropriateScoresFromCounts([
            'inappropriate_words_count' => 2,
            'operator_inappropriate_words_count' => null,
            'customer_inappropriate_words_count' => null,
        ]);

        $this->assertSame(80, $scores['operator']);
        $this->assertSame(80, $scores['customer']);
    }

    public function test_inappropriate_scores_from_counts_use_speaker_split_when_present(): void
    {
        $service = new CallScoringService;

        $scores = $service->inappropriateScoresFromCounts([
            'inappropriate_words_count' => 3,
            'operator_inappropriate_words_count' => 1,
            'customer_inappropriate_words_count' => 2,
        ]);

        $this->assertSame(90, $scores['operator']);
        $this->assertSame(80, $scores['customer']);
    }

    public function test_calculate_interruption_count_returns_zero_for_fewer_than_two_segments(): void
    {
        $service = new CallScoringService;

        $this->assertSame(0, $service->calculateInterruptionCount([]));
        $this->assertSame(0, $service->calculateInterruptionCount([
            ['speaker' => 'spk_1', 'start' => 0.0, 'end' => 1.0],
        ]));
    }

    public function test_calculate_interruption_count_counts_cross_speaker_overlaps(): void
    {
        $service = new CallScoringService;

        $this->assertSame(1, $service->calculateInterruptionCount([
            ['speaker' => 'spk_1', 'start' => 0.0, 'end' => 5.0],
            ['speaker' => 'spk_2', 'start' => 4.0, 'end' => 6.0],
        ]));
    }

    public function test_calculate_interruption_count_ignores_same_speaker_overlaps(): void
    {
        $service = new CallScoringService;

        $this->assertSame(0, $service->calculateInterruptionCount([
            ['speaker' => 'spk_1', 'start' => 0.0, 'end' => 5.0],
            ['speaker' => 'spk_1', 'start' => 4.0, 'end' => 6.0],
        ]));
    }

    public function test_calculate_interruption_count_sorts_segments_by_start_time(): void
    {
        $service = new CallScoringService;

        $this->assertSame(1, $service->calculateInterruptionCount([
            ['speaker' => 'spk_2', 'start' => 4.0, 'end' => 6.0],
            ['speaker' => 'spk_1', 'start' => 0.0, 'end' => 5.0],
        ]));
    }

    public function test_calculate_interruption_count_does_not_count_adjacent_non_overlapping_segments(): void
    {
        $service = new CallScoringService;

        $this->assertSame(0, $service->calculateInterruptionCount([
            ['speaker' => 'spk_1', 'start' => 0.0, 'end' => 5.0],
            ['speaker' => 'spk_2', 'start' => 5.0, 'end' => 8.0],
        ]));
    }

    public function test_calculate_interruption_count_accumulates_multiple_interruptions(): void
    {
        $service = new CallScoringService;

        $this->assertSame(2, $service->calculateInterruptionCount([
            ['speaker' => 'spk_1', 'start' => 0.0, 'end' => 10.0],
            ['speaker' => 'spk_2', 'start' => 5.0, 'end' => 8.0],
            ['speaker' => 'spk_1', 'start' => 7.0, 'end' => 12.0],
        ]));
    }

    public function test_calculate_interruption_counts_tracks_per_speaker_direction(): void
    {
        $service = new CallScoringService;

        $counts = $service->calculateInterruptionCounts([
            ['speaker' => 'operator', 'start' => 0.0, 'end' => 10.0],
            ['speaker' => 'musteri', 'start' => 5.0, 'end' => 8.0],
            ['speaker' => 'operator', 'start' => 7.0, 'end' => 12.0],
        ]);

        $this->assertSame(2, $counts['interruption_count']);
        $this->assertSame(1, $counts['customer_interruption_count']);
        $this->assertSame(1, $counts['operator_interruption_count']);
    }

    public function test_calculate_interruption_counts_operator_only(): void
    {
        $service = new CallScoringService;

        $counts = $service->calculateInterruptionCounts([
            ['speaker' => 'musteri', 'start' => 0.0, 'end' => 10.0],
            ['speaker' => 'operator', 'start' => 5.0, 'end' => 8.0],
        ]);

        $this->assertSame(1, $counts['interruption_count']);
        $this->assertSame(1, $counts['operator_interruption_count']);
        $this->assertSame(0, $counts['customer_interruption_count']);
    }

    public function test_score_from_event_count_subtracts_ten_points_per_event(): void
    {
        $service = new CallScoringService;

        $this->assertSame(100, $service->scoreFromEventCount(0));
        $this->assertSame(90, $service->scoreFromEventCount(1));
        $this->assertSame(0, $service->scoreFromEventCount(10));
        $this->assertSame(0, $service->scoreFromEventCount(12));
    }

    public function test_ser_overall_matches_the_gemini_overall_for_the_same_scores_counts_and_settings(): void
    {
        $settings = CallScoringService::WEIGHT_DEFAULTS;
        $settings['weight_courtesy'] = 45;

        foreach ($settings as $key => $value) {
            Setting::setValue($key, $value, 'scoring_params');
        }

        $service = new CallScoringService;
        $scores = [
            'politeness' => 80,
            'rudeness' => 20,
            'emotional_tone' => 75,
        ];
        $counts = [
            'operator_interruptions' => 0,
            'customer_interruptions' => 1,
            'operator_inappropriate' => 2,
            'customer_inappropriate' => 2,
            'inappropriate_words' => 4,
        ];

        $inputs = [
            'politeness' => $scores['politeness'],
            'rudeness' => $scores['rudeness'],
            'emotional_tone' => $scores['emotional_tone'],
            'operator_interruptions' => $counts['operator_interruptions'],
            'customer_interruptions' => $counts['customer_interruptions'],
            'operator_inappropriate' => $counts['operator_inappropriate'],
            'customer_inappropriate' => $counts['customer_inappropriate'],
        ];

        $serOverall = $service->calculateOverall($inputs, $service->overallWeights(), $service->gamma());
        $geminiOverall = $service->calculateTotalScore(
            courtesyScore: $scores['politeness'],
            operatorInterruptionScore: $service->scoreFromEventCount($counts['operator_interruptions']),
            customerInterruptionScore: $service->scoreFromEventCount($counts['customer_interruptions']),
            rudenessScore: $scores['rudeness'],
            emotionalToneScore: $scores['emotional_tone'],
            operatorInappropriateScore: $service->scoreFromEventCount($counts['operator_inappropriate']),
            customerInappropriateScore: $service->scoreFromEventCount($counts['customer_inappropriate']),
        );

        $this->assertSame(40, $serOverall);
        $this->assertSame($geminiOverall, $serOverall);
    }

    public function test_ser_overall_is_null_when_an_acoustic_score_is_missing(): void
    {
        $service = new CallScoringService;
        $serScore = new SerScore([
            'courtesy_score' => null,
            'rudeness_score' => 10,
            'emotional_tone_score' => 20,
        ]);

        $this->assertNull($service->calculateSerOverall($serScore, new CallAnalysis([
            'operator_interruption_count' => 1,
            'customer_interruption_count' => 0,
            'operator_inappropriate_words_count' => 1,
            'customer_inappropriate_words_count' => 0,
            'inappropriate_words_count' => 1,
        ])));
    }

    public function test_calculate_total_score_deducts_each_category_penalty_from_one_hundred(): void
    {
        $this->seedDefaultWeights();

        $service = new CallScoringService;

        $this->assertSame(38, $service->calculateTotalScore(
            courtesyScore: 80,
            operatorInterruptionScore: 100,
            customerInterruptionScore: 90,
            rudenessScore: 20,
            emotionalToneScore: 75,
            operatorInappropriateScore: 80,
            customerInappropriateScore: 80,
        ));
    }

    public function test_calculate_total_score_inverts_rudeness_before_weighting(): void
    {
        Setting::setValue('weight_courtesy', 0, 'scoring_params');
        Setting::setValue('weight_operator_interruption', 0, 'scoring_params');
        Setting::setValue('weight_customer_interruption', 0, 'scoring_params');
        Setting::setValue('weight_rudeness', 100, 'scoring_params');
        Setting::setValue('weight_emotion', 0, 'scoring_params');
        Setting::setValue('weight_operator_inappropriate', 0, 'scoring_params');
        Setting::setValue('weight_customer_inappropriate', 0, 'scoring_params');

        $service = new CallScoringService;

        $this->assertSame(13, $service->calculateTotalScore(
            courtesyScore: 0,
            operatorInterruptionScore: 0,
            customerInterruptionScore: 0,
            rudenessScore: 75,
            emotionalToneScore: 0,
            operatorInappropriateScore: 0,
            customerInappropriateScore: 0,
        ));
    }

    public function test_calculate_total_score_inverts_emotional_tone_before_weighting(): void
    {
        Setting::setValue('weight_courtesy', 0, 'scoring_params');
        Setting::setValue('weight_operator_interruption', 0, 'scoring_params');
        Setting::setValue('weight_customer_interruption', 0, 'scoring_params');
        Setting::setValue('weight_rudeness', 0, 'scoring_params');
        Setting::setValue('weight_emotion', 100, 'scoring_params');
        Setting::setValue('weight_operator_inappropriate', 0, 'scoring_params');
        Setting::setValue('weight_customer_inappropriate', 0, 'scoring_params');

        $service = new CallScoringService;

        $this->assertSame(13, $service->calculateTotalScore(
            courtesyScore: 0,
            operatorInterruptionScore: 0,
            customerInterruptionScore: 0,
            rudenessScore: 0,
            emotionalToneScore: 75,
            operatorInappropriateScore: 0,
            customerInappropriateScore: 0,
        ));
    }

    public function test_calculate_total_score_is_zero_when_quality_weights_sum_to_zero(): void
    {
        foreach (array_keys(CallScoringService::WEIGHT_DEFAULTS) as $key) {
            Setting::setValue($key, 0, 'scoring_params');
        }

        $service = new CallScoringService;

        $this->assertSame(0, $service->calculateTotalScore(
            courtesyScore: 0,
            operatorInterruptionScore: 0,
            customerInterruptionScore: 0,
            rudenessScore: 100,
            emotionalToneScore: 100,
            operatorInappropriateScore: 0,
            customerInappropriateScore: 0,
        ));
    }

    public function test_calculate_total_score_stays_at_one_hundred_when_every_category_is_perfect(): void
    {
        $this->seedDefaultWeights();

        $service = new CallScoringService;

        $this->assertSame(100, $service->calculateTotalScore(
            courtesyScore: 100,
            operatorInterruptionScore: 100,
            customerInterruptionScore: 100,
            rudenessScore: 0,
            emotionalToneScore: 0,
            operatorInappropriateScore: 100,
            customerInappropriateScore: 100,
        ));
    }

    public function test_calculate_total_score_is_zero_when_the_only_quality_score_is_zero(): void
    {
        foreach (CallScoringService::WEIGHT_DEFAULTS as $key => $value) {
            Setting::setValue($key, $key === 'weight_courtesy' ? 40 : 0, 'scoring_params');
        }

        $service = new CallScoringService;

        $this->assertSame(0, $service->calculateTotalScore(
            courtesyScore: 0,
            operatorInterruptionScore: 100,
            customerInterruptionScore: 100,
            rudenessScore: 0,
            emotionalToneScore: 0,
            operatorInappropriateScore: 100,
            customerInappropriateScore: 100,
        ));
    }

    public function test_calculate_total_score_applies_the_gamma_curve_to_the_quality_base(): void
    {
        foreach (CallScoringService::WEIGHT_DEFAULTS as $key => $value) {
            Setting::setValue($key, $key === 'weight_courtesy' ? 5 : 0, 'scoring_params');
        }

        Setting::setValue('score_gamma', '1.5', 'scoring_params');

        $service = new CallScoringService;

        $this->assertSame(72, $service->calculateTotalScore(
            courtesyScore: 80,
            operatorInterruptionScore: 100,
            customerInterruptionScore: 100,
            rudenessScore: 0,
            emotionalToneScore: 0,
            operatorInappropriateScore: 100,
            customerInappropriateScore: 100,
        ));
    }

    public function test_calculate_total_score_sums_penalties_and_clamps_at_zero(): void
    {
        foreach (CallScoringService::WEIGHT_DEFAULTS as $key => $value) {
            $weight = match ($key) {
                'weight_courtesy' => 60,
                'weight_rudeness' => 60,
                default => 0,
            };

            Setting::setValue($key, $weight, 'scoring_params');
        }

        $service = new CallScoringService;

        $this->assertSame(0, $service->calculateTotalScore(
            courtesyScore: 0,
            operatorInterruptionScore: 100,
            customerInterruptionScore: 100,
            rudenessScore: 100,
            emotionalToneScore: 0,
            operatorInappropriateScore: 100,
            customerInappropriateScore: 100,
        ));
    }

    public function test_calculate_overall_applies_quality_weights_gamma_and_event_penalties(): void
    {
        $service = new CallScoringService;

        $overall = $service->calculateOverall(
            [
                'politeness' => 45,
                'rudeness' => 48,
                'emotional_tone' => 68,
                'operator_interruptions' => 0,
                'customer_interruptions' => 0,
                'operator_inappropriate' => 1,
                'customer_inappropriate' => 1,
            ],
            [
                'politeness' => 10,
                'rudeness' => 30,
                'emotional_tone' => 10,
                'operator_interruptions' => 10,
                'customer_interruptions' => 20,
                'operator_inappropriate' => 10,
                'customer_inappropriate' => 30,
            ],
            1.5,
        );

        $this->assertSame(20, $overall);
    }

    public function test_calculate_overall_is_one_hundred_when_quality_is_perfect_and_there_are_no_events(): void
    {
        $service = new CallScoringService;

        $overall = $service->calculateOverall(
            [
                'politeness' => 100,
                'rudeness' => 0,
                'emotional_tone' => 0,
                'operator_interruptions' => 0,
                'customer_interruptions' => 0,
                'operator_inappropriate' => 0,
                'customer_inappropriate' => 0,
            ],
            [
                'politeness' => 10,
                'rudeness' => 30,
                'emotional_tone' => 10,
                'operator_interruptions' => 10,
                'customer_interruptions' => 20,
                'operator_inappropriate' => 10,
                'customer_inappropriate' => 30,
            ],
            1.5,
        );

        $this->assertSame(100, $overall);
    }

    public function test_calculate_overall_stays_positive_when_many_events_compound(): void
    {
        $service = new CallScoringService;

        $overall = $service->calculateOverall(
            [
                'politeness' => 85,
                'rudeness' => 10,
                'emotional_tone' => 35,
                'operator_interruptions' => 5,
                'customer_interruptions' => 7,
                'operator_inappropriate' => 0,
                'customer_inappropriate' => 0,
            ],
            [
                'politeness' => 10,
                'rudeness' => 30,
                'emotional_tone' => 10,
                'operator_interruptions' => 10,
                'customer_interruptions' => 20,
                'operator_inappropriate' => 10,
                'customer_inappropriate' => 30,
            ],
            1.5,
        );

        $this->assertSame(10, $overall);
    }

    public function test_calculate_overall_stays_inside_zero_and_one_hundred_after_heavy_event_penalties(): void
    {
        $service = new CallScoringService;

        $overall = $service->calculateOverall(
            [
                'politeness' => 50,
                'rudeness' => 50,
                'emotional_tone' => 50,
                'operator_interruptions' => 50,
                'customer_interruptions' => 50,
                'operator_inappropriate' => 50,
                'customer_inappropriate' => 50,
            ],
            [
                'politeness' => 10,
                'rudeness' => 30,
                'emotional_tone' => 10,
                'operator_interruptions' => 10,
                'customer_interruptions' => 20,
                'operator_inappropriate' => 10,
                'customer_inappropriate' => 30,
            ],
            1.5,
        );

        $this->assertGreaterThanOrEqual(0, $overall);
        $this->assertLessThanOrEqual(100, $overall);
    }

    public function test_calculate_overall_is_zero_when_an_event_weight_is_one_hundred_and_the_event_occurred(): void
    {
        $service = new CallScoringService;

        $overall = $service->calculateOverall(
            [
                'politeness' => 100,
                'rudeness' => 0,
                'emotional_tone' => 0,
                'operator_interruptions' => 1,
                'customer_interruptions' => 0,
                'operator_inappropriate' => 0,
                'customer_inappropriate' => 0,
            ],
            [
                'politeness' => 10,
                'rudeness' => 30,
                'emotional_tone' => 10,
                'operator_interruptions' => 100,
                'customer_interruptions' => 20,
                'operator_inappropriate' => 10,
                'customer_inappropriate' => 30,
            ],
            1.5,
        );

        $this->assertSame(0, $overall);
    }

    private function seedDefaultWeights(): void
    {
        foreach (CallScoringService::WEIGHT_DEFAULTS as $key => $value) {
            Setting::setValue($key, $value, 'scoring_params');
        }
    }
}
