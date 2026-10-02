<?php

namespace Tests\Feature;

use App\Models\Call;
use App\Models\CallAnalysis;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Cache;
use Inertia\Testing\AssertableInertia;
use PHPUnit\Framework\Assert;
use Tests\TestCase;

class DashboardTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        Cache::flush();
    }

    protected function tearDown(): void
    {
        $this->travelBack();

        parent::tearDown();
    }

    public function test_dashboard_stats_omit_high_buyer_potential(): void
    {
        $user = User::factory()->create();

        $this->actingAs($user)
            ->getJson('/api/dashboard')
            ->assertOk()
            ->assertJsonPath('range', '1M')
            ->assertJsonPath('stats.total_calls', 0)
            ->assertJsonPath('stats.problematic_calls', 0)
            ->assertJsonPath('stats.avg_score', null)
            ->assertJsonPath('chart.0.avg_score', null)
            ->assertJsonPath('chart.0.calls_count', 0)
            ->assertJsonCount(30, 'chart')
            ->assertJsonMissingPath('stats.problem_calls')
            ->assertJsonMissingPath('stats.high_buyer_potential');
    }

    public function test_completed_call_with_score_69_is_counted_in_dashboard_stats(): void
    {
        $this->travelTo('2026-10-01 12:00:00');
        $user = User::factory()->create();
        $call = $this->createScoredCall(Call::STATUS_COMPLETED, 69, '2026-10-01 11:15:00');

        $this->actingAs($user)
            ->get(route('dashboard', ['range' => '1D']))
            ->assertOk()
            ->assertInertia(fn (AssertableInertia $page) => $page
                ->component('Dashboard')
                ->where('range', '1D')
                ->where('stats.total_calls', 1)
                ->where('stats.avg_score', 69)
                ->where('stats.problematic_calls', 0)
                ->where('activities.0.id', $call->id)
                ->where('activities.0.title', 'Analiz tamamlandı')
                ->where('recentCalls.0.id', $call->id)
                ->where('recentCalls.0.score', 69)
                ->where('recentCalls.0.status', Call::STATUS_COMPLETED)
                ->where('traffic.0.label', 'Hazır')
                ->where('traffic.0.color', '#3b82f6')
                ->where('traffic.0.percent', 100)
                ->where('traffic.1.label', 'Gözləyir')
                ->where('traffic.1.color', '#fbbf24')
                ->where('traffic.2.label', 'Problemli')
                ->where('traffic.2.color', '#f43f5e')
                ->where('stats.sparklines.total_calls', fn (Collection $points): bool => $points->count() === 24 && $points->sum() === 1)
                ->where('stats.sparklines.avg_score', function (Collection $points): bool {
                    return $points->contains(fn (mixed $value): bool => $value === 69)
                        && $points->contains(fn (mixed $value): bool => $value === null)
                        && ! $points->contains(fn (mixed $value): bool => $value === 0);
                })
                ->where('chart', function (Collection $chart): bool {
                    $filled = $chart->firstWhere('date', '2026-10-01T11:00:00+00:00');
                    $empty = $chart->firstWhere('date', '2026-10-01T10:00:00+00:00');

                    Assert::assertIsArray($filled);
                    Assert::assertSame(69, $filled['avg_score']);
                    Assert::assertSame(1, $filled['calls_count']);
                    Assert::assertIsArray($empty);
                    Assert::assertNull($empty['avg_score']);
                    Assert::assertSame(0, $empty['calls_count']);
                    Assert::assertCount(24, $chart);

                    return true;
                }));
    }

    public function test_failed_calls_and_calls_outside_the_range_are_excluded(): void
    {
        $this->travelTo('2026-10-01 12:00:00');
        $user = User::factory()->create();
        $this->createScoredCall(Call::STATUS_FAILED, 10, '2026-10-01 11:00:00');
        $this->createScoredCall(Call::STATUS_COMPLETED, 50, '2026-08-01 11:00:00');

        $this->actingAs($user)
            ->getJson('/api/dashboard?range=1M')
            ->assertOk()
            ->assertJsonPath('stats.total_calls', 0)
            ->assertJsonPath('stats.avg_score', null)
            ->assertJsonPath('stats.problematic_calls', 0)
            ->assertJsonCount(0, 'recentCalls')
            ->assertJsonCount(0, 'activities');
    }

    public function test_score_below_the_threshold_is_problematic_and_the_threshold_is_not(): void
    {
        $this->travelTo('2026-10-01 12:00:00');
        $user = User::factory()->create();
        $this->createScoredCall(Call::STATUS_COMPLETED, 39, '2026-10-01 10:00:00');
        $this->createScoredCall(Call::STATUS_COMPLETED, 40, '2026-10-01 11:00:00');

        $this->actingAs($user)
            ->getJson('/api/dashboard?range=1D')
            ->assertOk()
            ->assertJsonPath('stats.total_calls', 2)
            ->assertJsonPath('stats.avg_score', 39.5)
            ->assertJsonPath('stats.problematic_calls', 1)
            ->assertJsonPath('traffic.0.count', 1)
            ->assertJsonPath('traffic.0.percent', 50)
            ->assertJsonPath('traffic.2.count', 1)
            ->assertJsonPath('traffic.2.percent', 50);
    }

    public function test_rudeness_counts_as_problematic_when_the_score_is_high(): void
    {
        $this->travelTo('2026-10-01 12:00:00');
        $user = User::factory()->create();
        $call = $this->createScoredCall(Call::STATUS_COMPLETED, 80, '2026-10-01 11:00:00', rudeness: true);

        $this->actingAs($user)
            ->getJson('/api/dashboard?range=1D')
            ->assertOk()
            ->assertJsonPath('stats.total_calls', 1)
            ->assertJsonPath('stats.avg_score', 80)
            ->assertJsonPath('stats.problematic_calls', 1)
            ->assertJsonPath('traffic.0.count', 0)
            ->assertJsonPath('traffic.2.count', 1)
            ->assertJsonPath('traffic.2.percent', 100)
            ->assertJsonPath('activities.0.id', $call->id)
            ->assertJsonPath('activities.0.title', 'Problemli zəng aşkarlandı')
            ->assertJsonPath('recentCalls.0.id', $call->id)
            ->assertJsonPath('recentCalls.0.has_problem', true);
    }

    public function test_manual_score_overrides_total_score(): void
    {
        $this->travelTo('2026-10-01 12:00:00');
        $user = User::factory()->create();
        $this->createScoredCall(Call::STATUS_COMPLETED, 20, '2026-10-01 11:00:00', manualScore: 90);

        $this->actingAs($user)
            ->getJson('/api/dashboard?range=1D')
            ->assertOk()
            ->assertJsonPath('stats.avg_score', 90)
            ->assertJsonPath('stats.problematic_calls', 0)
            ->assertJsonPath('recentCalls.0.score', 90);
    }

    public function test_pending_calls_count_as_waiting_without_an_average(): void
    {
        $this->travelTo('2026-10-01 12:00:00');
        $user = User::factory()->create();
        Call::factory()->create([
            'status' => Call::STATUS_PENDING,
            'called_at' => '2026-10-01 11:00:00',
            'created_at' => '2026-10-01 11:00:00',
        ]);

        $response = $this->actingAs($user)->getJson('/api/dashboard?range=1D');

        $response->assertOk()
            ->assertJsonPath('stats.total_calls', 1)
            ->assertJsonPath('stats.avg_score', null)
            ->assertJsonPath('stats.problematic_calls', 0)
            ->assertJsonPath('traffic.1.label', 'Gözləyir')
            ->assertJsonPath('traffic.1.percent', 100)
            ->assertJsonCount(0, 'recentCalls')
            ->assertJsonCount(0, 'activities');

        $hour = collect($response->json('chart'))->firstWhere('date', '2026-10-01T11:00:00+00:00');
        $empty = collect($response->json('chart'))->firstWhere('date', '2026-10-01T09:00:00+00:00');

        $this->assertSame(1, $hour['calls_count']);
        $this->assertNull($hour['avg_score']);
        $this->assertSame(0, $empty['calls_count']);
        $this->assertNull($empty['avg_score']);
    }

    public function test_ranges_use_hourly_or_daily_buckets(): void
    {
        $this->travelTo('2026-10-01 12:00:00');
        $user = User::factory()->create();

        $this->actingAs($user)
            ->getJson('/api/dashboard?range=1D')
            ->assertOk()
            ->assertJsonPath('range', '1D')
            ->assertJsonCount(24, 'chart')
            ->assertJsonPath('chart.0.date', '2026-09-30T13:00:00+00:00');

        $this->actingAs($user)
            ->getJson('/api/dashboard?range=1W')
            ->assertOk()
            ->assertJsonPath('range', '1W')
            ->assertJsonCount(7, 'chart')
            ->assertJsonPath('chart.0.date', '2026-09-25');

        $this->actingAs($user)
            ->getJson('/api/dashboard?period=today')
            ->assertOk()
            ->assertJsonPath('range', '1D')
            ->assertJsonCount(24, 'chart');
    }

    public function test_dashboard_stats_stay_cached_until_an_analysis_score_is_saved(): void
    {
        $this->travelTo('2026-10-01 12:00:00');
        $user = User::factory()->create();

        $this->actingAs($user)
            ->getJson('/api/dashboard?range=1D')
            ->assertOk()
            ->assertJsonPath('stats.total_calls', 0);

        $call = Call::factory()->create([
            'status' => Call::STATUS_COMPLETED,
            'called_at' => '2026-10-01 11:00:00',
            'created_at' => '2026-10-01 11:00:00',
        ]);

        $this->actingAs($user)
            ->getJson('/api/dashboard?range=1D')
            ->assertOk()
            ->assertJsonPath('stats.total_calls', 0);

        CallAnalysis::query()->create([
            'call_id' => $call->id,
            'total_score' => 69,
        ]);

        $this->actingAs($user)
            ->getJson('/api/dashboard?range=1D')
            ->assertOk()
            ->assertJsonPath('stats.total_calls', 1)
            ->assertJsonPath('stats.avg_score', 69)
            ->assertJsonPath('recentCalls.0.id', $call->id)
            ->assertJsonPath('activities.0.title', 'Analiz tamamlandı');
    }

    public function test_guest_is_redirected_from_the_dashboard(): void
    {
        $this->get(route('dashboard'))->assertRedirect(route('login'));
    }

    public function test_guest_receives_401_from_the_dashboard_api(): void
    {
        $this->getJson('/api/dashboard')->assertUnauthorized();
    }

    private function createScoredCall(
        string $status,
        ?int $score,
        string $at,
        bool $rudeness = false,
        ?int $manualScore = null,
    ): Call {
        $call = Call::factory()->create([
            'status' => $status,
            'called_at' => $at,
            'created_at' => $at,
        ]);

        CallAnalysis::query()->create([
            'call_id' => $call->id,
            'total_score' => $score,
            'manual_score' => $manualScore,
            'has_rudeness' => $rudeness,
        ]);

        return $call;
    }
}
