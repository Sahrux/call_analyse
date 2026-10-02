<?php

namespace App\Services;

use App\Models\CallAnalysis;
use App\Models\SerScore;
use App\Models\Setting;

class CallScoringService
{
    /**
     * Points subtracted from an event-count category (0–100) for each overlap
     * or inappropriate-word match. Ten events reach 0.
     */
    public const DISRUPTION_POINTS_PER_EVENT = 10;

    /**
     * Default scoring weights. Quality weights share the overall base.
     * Event weights are the percent taken off the overall for each occurrence.
     * They are not required to sum to 100.
     *
     * @var array<string, int>
     */
    public const WEIGHT_DEFAULTS = [
        'weight_courtesy' => 25,
        'weight_operator_interruption' => 10,
        'weight_customer_interruption' => 10,
        'weight_rudeness' => 15,
        'weight_emotion' => 10,
        'weight_operator_inappropriate' => 7,
        'weight_customer_inappropriate' => 8,
    ];

    public const GAMMA_DEFAULT = 1.5;

    /**
     * Manual review stores interruption and inappropriate categories as 0–100
     * scores (10 points per event). Those scores are converted back to counts
     * and passed to calculateOverall().
     */
    public function calculateTotalScore(
        int $courtesyScore,
        int $operatorInterruptionScore,
        int $customerInterruptionScore,
        int $rudenessScore,
        int $emotionalToneScore,
        int $operatorInappropriateScore,
        int $customerInappropriateScore,
    ): int {
        return $this->calculateOverall(
            [
                'politeness' => $courtesyScore,
                'rudeness' => $rudenessScore,
                'emotional_tone' => $emotionalToneScore,
                'operator_interruptions' => $this->eventCountFromScore($operatorInterruptionScore),
                'customer_interruptions' => $this->eventCountFromScore($customerInterruptionScore),
                'operator_inappropriate' => $this->eventCountFromScore($operatorInappropriateScore),
                'customer_inappropriate' => $this->eventCountFromScore($customerInappropriateScore),
            ],
            $this->overallWeights(),
            $this->gamma(),
        );
    }

    /**
     * Quality categories form a weighted average, then a gamma curve, then a
     * compounding percent penalty for each interruption or inappropriate expression.
     *
     * Rudeness and emotional tone are higher-is-worse and inverted before the average.
     *
     * @param  array{
     *     politeness: float|int,
     *     rudeness: float|int,
     *     emotional_tone: float|int,
     *     operator_interruptions: float|int,
     *     customer_interruptions: float|int,
     *     operator_inappropriate: float|int,
     *     customer_inappropriate: float|int
     * }  $scores
     * @param  array{
     *     politeness: float|int,
     *     rudeness: float|int,
     *     emotional_tone: float|int,
     *     operator_interruptions: float|int,
     *     customer_interruptions: float|int,
     *     operator_inappropriate: float|int,
     *     customer_inappropriate: float|int
     * }  $weights
     */
    public function calculateOverall(array $scores, array $weights, float $gamma = 1.5): int
    {
        $quality = [
            [(float) $scores['politeness'], (float) $weights['politeness']],
            [100 - (float) $scores['rudeness'], (float) $weights['rudeness']],
            [100 - (float) $scores['emotional_tone'], (float) $weights['emotional_tone']],
        ];

        $sum = 0.0;
        $weightSum = 0.0;

        foreach ($quality as [$score, $weight]) {
            $sum += $score * $weight;
            $weightSum += $weight;
        }

        $base = $weightSum > 0 ? $sum / $weightSum : 0.0;
        $overall = 100 * pow(max(0.0, min(100.0, $base)) / 100, $gamma);

        foreach (['operator_interruptions', 'customer_interruptions', 'operator_inappropriate', 'customer_inappropriate'] as $key) {
            $perEvent = max(0.0, min(1.0, 1 - ((float) $weights[$key] / 100)));
            $overall *= pow($perEvent, (float) $scores[$key]);
        }

        return (int) round(max(0.0, min(100.0, $overall)));
    }

    /**
     * Acoustic overall for the SER card. Null when any acoustic score is missing.
     */
    public function calculateSerOverall(?SerScore $serScore, ?CallAnalysis $analysis): ?int
    {
        if (
            $serScore === null
            || $serScore->courtesy_score === null
            || $serScore->rudeness_score === null
            || $serScore->emotional_tone_score === null
        ) {
            return null;
        }

        return $this->calculateOverall(
            $this->overallInputs(
                politeness: (float) $serScore->courtesy_score,
                rudeness: (float) $serScore->rudeness_score,
                emotionalTone: (float) $serScore->emotional_tone_score,
                operatorInterruptions: (int) ($analysis?->operator_interruption_count ?? 0),
                customerInterruptions: (int) ($analysis?->customer_interruption_count ?? 0),
                inappropriateCounts: [
                    'inappropriate_words_count' => (int) ($analysis?->inappropriate_words_count ?? 0),
                    'operator_inappropriate_words_count' => $analysis?->operator_inappropriate_words_count,
                    'customer_inappropriate_words_count' => $analysis?->customer_inappropriate_words_count,
                ],
            ),
            $this->overallWeights(),
            $this->gamma(),
        );
    }

    /**
     * @param  array{inappropriate_words_count?: int|null, operator_inappropriate_words_count?: int|null, customer_inappropriate_words_count?: int|null}  $inappropriateCounts
     * @return array{
     *     politeness: float|int,
     *     rudeness: float|int,
     *     emotional_tone: float|int,
     *     operator_interruptions: int,
     *     customer_interruptions: int,
     *     operator_inappropriate: int,
     *     customer_inappropriate: int
     * }
     */
    public function overallInputs(
        float|int $politeness,
        float|int $rudeness,
        float|int $emotionalTone,
        int $operatorInterruptions,
        int $customerInterruptions,
        array $inappropriateCounts,
    ): array {
        $inappropriate = $this->inappropriateEventCounts($inappropriateCounts);

        return [
            'politeness' => $politeness,
            'rudeness' => $rudeness,
            'emotional_tone' => $emotionalTone,
            'operator_interruptions' => $operatorInterruptions,
            'customer_interruptions' => $customerInterruptions,
            'operator_inappropriate' => $inappropriate['operator'],
            'customer_inappropriate' => $inappropriate['customer'],
        ];
    }

    /**
     * @return array{
     *     politeness: int,
     *     rudeness: int,
     *     emotional_tone: int,
     *     operator_interruptions: int,
     *     customer_interruptions: int,
     *     operator_inappropriate: int,
     *     customer_inappropriate: int
     * }
     */
    public function overallInputsFromAnalysis(CallAnalysis $analysis): array
    {
        return $this->overallInputs(
            politeness: (int) $analysis->courtesy_score,
            rudeness: (int) $analysis->rudeness_score,
            emotionalTone: (int) $analysis->emotional_tone_score,
            operatorInterruptions: (int) ($analysis->operator_interruption_count ?? 0),
            customerInterruptions: (int) ($analysis->customer_interruption_count ?? 0),
            inappropriateCounts: [
                'inappropriate_words_count' => (int) ($analysis->inappropriate_words_count ?? 0),
                'operator_inappropriate_words_count' => $analysis->operator_inappropriate_words_count,
                'customer_inappropriate_words_count' => $analysis->customer_inappropriate_words_count,
            ],
        );
    }

    public function scoreFromEventCount(int $eventCount): int
    {
        return max(0, 100 - (self::DISRUPTION_POINTS_PER_EVENT * $eventCount));
    }

    /**
     * @return array<string, int>
     */
    public function weights(): array
    {
        $weights = [];

        foreach (self::WEIGHT_DEFAULTS as $key => $default) {
            $weights[$key] = (int) Setting::getValue($key, $default);
        }

        return $weights;
    }

    /**
     * @return array{
     *     politeness: int,
     *     rudeness: int,
     *     emotional_tone: int,
     *     operator_interruptions: int,
     *     customer_interruptions: int,
     *     operator_inappropriate: int,
     *     customer_inappropriate: int
     * }
     */
    public function overallWeights(): array
    {
        $weights = $this->weights();

        return [
            'politeness' => $weights['weight_courtesy'],
            'rudeness' => $weights['weight_rudeness'],
            'emotional_tone' => $weights['weight_emotion'],
            'operator_interruptions' => $weights['weight_operator_interruption'],
            'customer_interruptions' => $weights['weight_customer_interruption'],
            'operator_inappropriate' => $weights['weight_operator_inappropriate'],
            'customer_inappropriate' => $weights['weight_customer_inappropriate'],
        ];
    }

    public function gamma(): float
    {
        $gamma = (float) Setting::getValue('score_gamma', self::GAMMA_DEFAULT);

        return max(1.0, min(3.0, $gamma));
    }

    public function eventCountFromScore(int $score): int
    {
        $clamped = max(0, min(100, $score));

        return (int) round((100 - $clamped) / self::DISRUPTION_POINTS_PER_EVENT);
    }

    public function countInappropriateWords(string $transcript, string $language): int
    {
        $transcript = mb_strtolower($transcript, 'UTF-8');

        /** @var list<string>|null $dictionary */
        $dictionary = config('inappropriate_words.'.strtolower($language));

        if (! is_array($dictionary) || $dictionary === []) {
            return 0;
        }

        $count = 0;

        foreach ($dictionary as $term) {
            if (! is_string($term) || $term === '') {
                continue;
            }

            $matches = preg_match_all($this->wordBoundaryPattern($term), $transcript);

            if ($matches !== false && $matches > 0) {
                $count += $matches;
            }
        }

        return $count;
    }

    /**
     * Split Gemini inappropriate_expressions by speaker, or fall back to the
     * dictionary total when Gemini omitted the array. Dictionary matches have
     * no speaker, so operator/customer counts stay null in that case.
     *
     * @param  list<array{speaker?: string|null, text?: string|null}>|null  $expressions
     * @return array{inappropriate_words_count: int, operator_inappropriate_words_count: int|null, customer_inappropriate_words_count: int|null}
     */
    public function resolveInappropriateWordCounts(?array $expressions, string $transcript, string $language): array
    {
        if ($expressions === null) {
            return [
                'inappropriate_words_count' => $this->countInappropriateWords($transcript, $language),
                'operator_inappropriate_words_count' => null,
                'customer_inappropriate_words_count' => null,
            ];
        }

        $operatorCount = 0;
        $customerCount = 0;

        foreach ($expressions as $expression) {
            if (! is_array($expression)) {
                continue;
            }

            $speaker = $expression['speaker'] ?? null;
            $role = $this->resolveSpeakerRole(is_string($speaker) ? $speaker : null);

            if ($role === 'operator') {
                $operatorCount++;
            } elseif ($role === 'musteri') {
                $customerCount++;
            }
        }

        return [
            'inappropriate_words_count' => $operatorCount + $customerCount,
            'operator_inappropriate_words_count' => $operatorCount,
            'customer_inappropriate_words_count' => $customerCount,
        ];
    }

    /**
     * Convert speaker-split counts to 0–100 scores. When the speaker split is
     * unavailable, both categories use the combined dictionary total so the
     * stored counts are not fabricated.
     *
     * @param  array{inappropriate_words_count: int, operator_inappropriate_words_count: int|null, customer_inappropriate_words_count: int|null}  $counts
     * @return array{operator: int, customer: int}
     */
    public function inappropriateScoresFromCounts(array $counts): array
    {
        $fallbackCount = $counts['inappropriate_words_count'];

        return [
            'operator' => $this->scoreFromEventCount($counts['operator_inappropriate_words_count'] ?? $fallbackCount),
            'customer' => $this->scoreFromEventCount($counts['customer_inappropriate_words_count'] ?? $fallbackCount),
        ];
    }

    /**
     * Speaker counts fall back to the combined dictionary total when a side is missing,
     * matching inappropriateScoresFromCounts().
     *
     * @param  array{inappropriate_words_count?: int|null, operator_inappropriate_words_count?: int|null, customer_inappropriate_words_count?: int|null}  $counts
     * @return array{operator: int, customer: int}
     */
    public function inappropriateEventCounts(array $counts): array
    {
        $fallbackCount = (int) ($counts['inappropriate_words_count'] ?? 0);

        return [
            'operator' => (int) ($counts['operator_inappropriate_words_count'] ?? $fallbackCount),
            'customer' => (int) ($counts['customer_inappropriate_words_count'] ?? $fallbackCount),
        ];
    }

    /**
     * @param  list<array{speaker?: string|null, start?: float|int|null, end?: float|int|null}>  $segments
     */
    public function calculateInterruptionCount(array $segments): int
    {
        return $this->calculateInterruptionCounts($segments)['interruption_count'];
    }

    /**
     * @param  list<array{speaker?: string|null, start?: float|int|null, end?: float|int|null}>  $segments
     * @return array{interruption_count: int, operator_interruption_count: int, customer_interruption_count: int}
     */
    public function calculateInterruptionCounts(array $segments): array
    {
        if (count($segments) < 2) {
            return [
                'interruption_count' => 0,
                'operator_interruption_count' => 0,
                'customer_interruption_count' => 0,
            ];
        }

        usort(
            $segments,
            static fn (array $left, array $right): int => ((float) ($left['start'] ?? 0)) <=> ((float) ($right['start'] ?? 0))
        );

        $totalCount = 0;
        $operatorCount = 0;
        $customerCount = 0;
        $previous = $segments[0];

        for ($index = 1; $index < count($segments); $index++) {
            $current = $segments[$index];

            if (
                isset($current['start'], $previous['end'])
                && (float) $current['start'] < (float) $previous['end']
                && ($current['speaker'] ?? null) !== ($previous['speaker'] ?? null)
            ) {
                $totalCount++;

                $interrupter = $this->resolveSpeakerRole($current['speaker'] ?? null);

                if ($interrupter === 'operator') {
                    $operatorCount++;
                } elseif ($interrupter === 'musteri') {
                    $customerCount++;
                }
            }

            $previous = $current;
        }

        return [
            'interruption_count' => $totalCount,
            'operator_interruption_count' => $operatorCount,
            'customer_interruption_count' => $customerCount,
        ];
    }

    private function resolveSpeakerRole(?string $speaker): ?string
    {
        if ($speaker === null || $speaker === '') {
            return null;
        }

        $normalized = strtolower($speaker);

        return match (true) {
            $normalized === 'operator' => 'operator',
            in_array($normalized, ['musteri', 'customer'], true) => 'musteri',
            preg_match('/^spk[:_]?(\d+)$/i', $speaker, $matches) === 1 => ((int) $matches[1] % 2) === 0 ? 'operator' : 'musteri',
            default => null,
        };
    }

    private function wordBoundaryPattern(string $term): string
    {
        $parts = preg_split('/\s+/u', mb_strtolower($term, 'UTF-8'), -1, PREG_SPLIT_NO_EMPTY);

        if ($parts === false || $parts === []) {
            $parts = [mb_strtolower($term, 'UTF-8')];
        }

        $escaped = implode(
            '\s+',
            array_map(static fn (string $part): string => preg_quote($part, '/'), $parts)
        );

        return '/(?<![\p{L}\p{N}])'.$escaped.'(?![\p{L}\p{N}])/u';
    }
}
