<?php

namespace App\Services;

use App\Models\Call;
use App\Models\Operator;
use App\Models\Setting;
use Carbon\CarbonInterface;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Cache;

class DashboardStats
{
    public const RANGES = ['1D', '1W', '1M'];

    private const LOW_SCORE_FALLBACK = 40;

    /**
     * @var array<string, array{label: string, color: string}>
     */
    private const TRAFFIC = [
        'ready' => ['label' => 'Hazır', 'color' => '#3b82f6'],
        'waiting' => ['label' => 'Gözləyir', 'color' => '#fbbf24'],
        'problematic' => ['label' => 'Problemli', 'color' => '#f43f5e'],
    ];

    /**
     * @return array{
     *     range: string,
     *     stats: array{
     *         total_calls: int,
     *         avg_score: float|null,
     *         problematic_calls: int,
     *         sparklines: array{
     *             total_calls: list<int>,
     *             avg_score: list<float|null>,
     *             problematic_calls: list<int>
     *         }
     *     },
     *     traffic: list<array{key: string, label: string, count: int, percent: int, color: string}>,
     *     chart: list<array{date: string, avg_score: float|null, calls_count: int}>,
     *     activities: list<array{id: int, type: string, title: string, subtitle: string, time: string}>,
     *     recentCalls: list<array{id: int, customer_name: string|null, customer_phone: string|null, operator: string|null, status: string, score: int|null, called_at: string|null, has_problem: bool}>,
     *     topOperators: list<array{id: int, name: string, employee_code: string|null, avg_score: float|null}>,
     *     riskOperators: list<array{id: int, name: string, employee_code: string|null, avg_score: float|null}>
     * }
     */
    public function remember(string $range): array
    {
        $range = $this->normalizeRange($range);

        return Cache::remember($this->cacheKey($range), 60, fn (): array => $this->build($range));
    }

    public function forget(): void
    {
        foreach (self::RANGES as $range) {
            Cache::forget($this->cacheKey($range));
        }
    }

    public function resolveRange(?string $range, ?string $period): string
    {
        if (in_array($range, self::RANGES, true)) {
            return $range;
        }

        return match ($period) {
            'today' => '1D',
            'week' => '1W',
            default => '1M',
        };
    }

    public function normalizeRange(string $range): string
    {
        return in_array($range, self::RANGES, true) ? $range : '1M';
    }

    /**
     * @return array{
     *     range: string,
     *     stats: array{
     *         total_calls: int,
     *         avg_score: float|null,
     *         problematic_calls: int,
     *         sparklines: array{
     *             total_calls: list<int>,
     *             avg_score: list<float|null>,
     *             problematic_calls: list<int>
     *         }
     *     },
     *     traffic: list<array{key: string, label: string, count: int, percent: int, color: string}>,
     *     chart: list<array{date: string, avg_score: float|null, calls_count: int}>,
     *     activities: list<array{id: int, type: string, title: string, subtitle: string, time: string}>,
     *     recentCalls: list<array{id: int, customer_name: string|null, customer_phone: string|null, operator: string|null, status: string, score: int|null, called_at: string|null, has_problem: bool}>,
     *     topOperators: list<array{id: int, name: string, employee_code: string|null, avg_score: float|null}>,
     *     riskOperators: list<array{id: int, name: string, employee_code: string|null, avg_score: float|null}>
     * }
     */
    private function build(string $range): array
    {
        [$start, $end, $unit] = $this->window($range);
        $threshold = (int) Setting::getValue('alert_score_threshold', self::LOW_SCORE_FALLBACK);

        $calls = Call::query()
            ->with([
                'operator:id,name',
                'analysis:id,call_id,total_score,manual_score,has_rudeness',
            ])
            ->whereIn('status', [Call::STATUS_COMPLETED, Call::STATUS_PENDING, Call::STATUS_PROCESSING])
            ->where(function ($query) use ($start, $end): void {
                $query->whereBetween('called_at', [$start, $end])
                    ->orWhere(function ($query) use ($start, $end): void {
                        $query->whereNull('called_at')
                            ->whereBetween('created_at', [$start, $end]);
                    });
            })
            ->get();

        $counted = $calls->filter(fn (Call $call): bool => $this->countsTowardTotal($call))->values();
        $scores = $counted
            ->map(fn (Call $call): ?int => $this->qualityScore($call))
            ->filter(fn (?int $score): bool => $score !== null)
            ->values();

        $buckets = $this->bucketSeries($calls, $counted, $start, $end, $unit, $threshold);
        $traffic = $this->traffic($calls, $threshold);
        $completed = $this->completedCalls($calls);

        return [
            'range' => $range,
            'stats' => [
                'total_calls' => $counted->count(),
                'avg_score' => $this->average($scores),
                'problematic_calls' => $calls->filter(fn (Call $call): bool => $this->isProblematic($call, $threshold))->count(),
                'sparklines' => [
                    'total_calls' => array_column($buckets, 'calls_count'),
                    'avg_score' => array_column($buckets, 'avg_score'),
                    'problematic_calls' => array_column($buckets, 'problematic_calls'),
                ],
            ],
            'traffic' => $traffic,
            'chart' => array_map(fn (array $bucket): array => [
                'date' => $bucket['date'],
                'avg_score' => $bucket['avg_score'],
                'calls_count' => $bucket['calls_count'],
            ], $buckets),
            'activities' => $completed->take(5)->map(fn (Call $call): array => $this->activity($call, $threshold))->all(),
            'recentCalls' => $completed->take(8)->map(fn (Call $call): array => $this->recentCall($call, $threshold))->all(),
            'topOperators' => $this->operators('desc'),
            'riskOperators' => $this->operators('asc'),
        ];
    }

    /**
     * @param  Collection<int, Call>  $calls
     * @param  Collection<int, Call>  $counted
     * @return list<array{date: string, avg_score: float|null, calls_count: int, problematic_calls: int}>
     */
    private function bucketSeries(Collection $calls, Collection $counted, CarbonInterface $start, CarbonInterface $end, string $unit, int $threshold): array
    {
        $countedByBucket = $counted->groupBy(fn (Call $call): string => $this->bucketKey($this->occurredAt($call), $unit));
        $problematicByBucket = $calls
            ->filter(fn (Call $call): bool => $this->isProblematic($call, $threshold))
            ->groupBy(fn (Call $call): string => $this->bucketKey($this->occurredAt($call), $unit));

        $series = [];
        $cursor = $start->copy();

        while ($cursor <= $end) {
            $key = $this->bucketKey($cursor, $unit);
            /** @var Collection<int, Call> $bucketCalls */
            $bucketCalls = $countedByBucket->get($key, collect());
            $bucketScores = $bucketCalls
                ->map(fn (Call $call): ?int => $this->qualityScore($call))
                ->filter(fn (?int $score): bool => $score !== null)
                ->values();

            $series[] = [
                'date' => $key,
                'avg_score' => $this->average($bucketScores),
                'calls_count' => $bucketCalls->count(),
                'problematic_calls' => $problematicByBucket->get($key, collect())->count(),
            ];

            $cursor = $unit === 'hour' ? $cursor->addHour() : $cursor->addDay();
        }

        return $series;
    }

    /**
     * @param  Collection<int, Call>  $calls
     * @return list<array{key: string, label: string, count: int, percent: int, color: string}>
     */
    private function traffic(Collection $calls, int $threshold): array
    {
        $counts = [
            'ready' => 0,
            'waiting' => 0,
            'problematic' => 0,
        ];

        foreach ($calls as $call) {
            if ($this->isProblematic($call, $threshold)) {
                $counts['problematic']++;
            } elseif ($call->status === Call::STATUS_COMPLETED) {
                $counts['ready']++;
            } else {
                $counts['waiting']++;
            }
        }

        $percents = $this->percentages($counts);
        $traffic = [];

        foreach (self::TRAFFIC as $key => $meta) {
            $traffic[] = [
                'key' => $key,
                'label' => $meta['label'],
                'count' => $counts[$key],
                'percent' => $percents[$key],
                'color' => $meta['color'],
            ];
        }

        return $traffic;
    }

    /**
     * @param  array<string, int>  $counts
     * @return array<string, int>
     */
    private function percentages(array $counts): array
    {
        $total = array_sum($counts);

        if ($total === 0) {
            return array_map(static fn (): int => 0, $counts);
        }

        $percentages = [];
        $remainders = [];

        foreach ($counts as $key => $count) {
            $exact = ($count / $total) * 100;
            $percentages[$key] = (int) floor($exact);
            $remainders[$key] = $exact - $percentages[$key];
        }

        $leftover = 100 - array_sum($percentages);
        arsort($remainders);

        foreach (array_keys($remainders) as $key) {
            if ($leftover <= 0) {
                break;
            }

            $percentages[$key]++;
            $leftover--;
        }

        return $percentages;
    }

    /**
     * @param  Collection<int, Call>  $calls
     * @return Collection<int, Call>
     */
    private function completedCalls(Collection $calls): Collection
    {
        return $calls
            ->filter(fn (Call $call): bool => $call->status === Call::STATUS_COMPLETED)
            ->sortByDesc(fn (Call $call): string => sprintf(
                '%010d-%010d',
                $this->occurredAt($call)->getTimestamp(),
                $call->id,
            ))
            ->values();
    }

    /**
     * @return array{id: int, type: string, title: string, subtitle: string, time: string}
     */
    private function activity(Call $call, int $threshold): array
    {
        $problematic = $this->isProblematic($call, $threshold);

        return [
            'id' => $call->id,
            'type' => $problematic ? 'alert' : 'completed',
            'title' => $problematic ? 'Problemli zəng aşkarlandı' : 'Analiz tamamlandı',
            'subtitle' => ($call->customer_name ?: 'Müştəri').' · '.($call->operator?->name ?: 'Operator yoxdur'),
            'time' => $this->occurredAt($call)->diffForHumans(),
        ];
    }

    /**
     * @return array{id: int, customer_name: string|null, customer_phone: string|null, operator: string|null, status: string, score: int|null, called_at: string|null, has_problem: bool}
     */
    private function recentCall(Call $call, int $threshold): array
    {
        return [
            'id' => $call->id,
            'customer_name' => $call->customer_name,
            'customer_phone' => $call->customer_phone,
            'operator' => $call->operator?->name,
            'status' => $call->status,
            'score' => $this->qualityScore($call),
            'called_at' => $this->occurredAt($call)->format('d.m.Y H:i'),
            'has_problem' => $this->isProblematic($call, $threshold),
        ];
    }

    /**
     * @return list<array{id: int, name: string, employee_code: string|null, avg_score: float|null}>
     */
    private function operators(string $direction): array
    {
        return Operator::query()
            ->where('is_active', true)
            ->whereNotNull('avg_score')
            ->orderBy('avg_score', $direction)
            ->limit(5)
            ->get(['id', 'name', 'employee_code', 'avg_score'])
            ->map(fn (Operator $operator): array => [
                'id' => $operator->id,
                'name' => $operator->name,
                'employee_code' => $operator->employee_code,
                'avg_score' => $operator->avg_score === null ? null : (float) $operator->avg_score,
            ])
            ->all();
    }

    /**
     * @param  Collection<int, int>  $scores
     */
    private function average(Collection $scores): ?float
    {
        if ($scores->isEmpty()) {
            return null;
        }

        return round((float) $scores->avg(), 1);
    }

    private function countsTowardTotal(Call $call): bool
    {
        return in_array($call->status, [Call::STATUS_COMPLETED, Call::STATUS_PENDING], true);
    }

    private function isProblematic(Call $call, int $threshold): bool
    {
        if ($call->analysis?->has_rudeness) {
            return true;
        }

        $score = $this->qualityScore($call);

        return $score !== null && $score < $threshold;
    }

    private function qualityScore(Call $call): ?int
    {
        $analysis = $call->analysis;

        if ($analysis === null) {
            return null;
        }

        $score = $analysis->manual_score ?? $analysis->total_score;

        return $score === null ? null : (int) $score;
    }

    private function occurredAt(Call $call): CarbonInterface
    {
        return $call->called_at ?? $call->created_at ?? now();
    }

    /**
     * @return array{0: CarbonInterface, 1: CarbonInterface, 2: string}
     */
    private function window(string $range): array
    {
        $end = now();

        if ($range === '1D') {
            return [$end->copy()->subHours(23)->startOfHour(), $end, 'hour'];
        }

        if ($range === '1W') {
            return [$end->copy()->subDays(6)->startOfDay(), $end, 'day'];
        }

        return [$end->copy()->subDays(29)->startOfDay(), $end, 'day'];
    }

    private function bucketKey(CarbonInterface $moment, string $unit): string
    {
        $bucket = $unit === 'hour'
            ? $moment->copy()->startOfHour()
            : $moment->copy()->startOfDay();

        return $unit === 'hour'
            ? $bucket->toIso8601String()
            : $bucket->toDateString();
    }

    private function cacheKey(string $range): string
    {
        return 'dashboard-stats:'.$range;
    }
}
