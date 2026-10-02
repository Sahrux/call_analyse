<?php

namespace Tests\Feature;

use App\Jobs\TranscribeCallJob;
use App\Models\Call;
use App\Models\User;
use Illuminate\Bus\UniqueLock;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;

class CallBulkReanalyzeTest extends TestCase
{
    use RefreshDatabase;

    public function test_reanalyze_requires_authentication(): void
    {
        $this->postJson('/api/calls/reanalyze', [
            'call_ids' => [1],
        ])->assertUnauthorized();
    }

    public function test_reanalyze_queues_failed_calls_and_skips_others(): void
    {
        Queue::fake([TranscribeCallJob::class]);

        $user = User::factory()->create();
        $failedA = Call::factory()->create([
            'status' => Call::STATUS_FAILED,
            'error_message' => 'first error',
        ]);
        $failedB = Call::factory()->create([
            'status' => Call::STATUS_FAILED,
            'error_message' => 'second error',
        ]);
        $completed = Call::factory()->create([
            'status' => Call::STATUS_COMPLETED,
        ]);

        $response = $this->actingAs($user)->postJson('/api/calls/reanalyze', [
            'call_ids' => [$failedA->id, $completed->id, $failedB->id],
        ]);

        $response->assertOk()
            ->assertJsonPath('queued_count', 2)
            ->assertJsonPath('queued_ids', [$failedA->id, $failedB->id])
            ->assertJsonPath('skipped.0.id', $completed->id)
            ->assertJsonPath('skipped.0.reason', 'status Xəta deyil');

        $this->assertSame(Call::STATUS_PENDING, $failedA->fresh()->status);
        $this->assertNull($failedA->fresh()->error_message);
        $this->assertSame(Call::STATUS_PENDING, $failedB->fresh()->status);
        $this->assertSame(Call::STATUS_COMPLETED, $completed->fresh()->status);

        Queue::assertPushed(TranscribeCallJob::class, 2);
        Queue::assertPushed(TranscribeCallJob::class, function (TranscribeCallJob $job) use ($failedA): bool {
            return $job->call->is($failedA);
        });
        Queue::assertPushed(TranscribeCallJob::class, function (TranscribeCallJob $job) use ($failedB): bool {
            return $job->call->is($failedB);
        });
    }

    public function test_reanalyze_rejects_empty_call_ids(): void
    {
        $user = User::factory()->create();

        $this->actingAs($user)
            ->postJson('/api/calls/reanalyze', [
                'call_ids' => [],
            ])
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['call_ids']);
    }

    public function test_reanalyze_releases_unique_locks_before_dispatch(): void
    {
        $user = User::factory()->create();
        $call = Call::factory()->create([
            'status' => Call::STATUS_FAILED,
            'error_message' => 'stuck',
        ]);

        $stuckJob = new TranscribeCallJob($call);
        $this->assertTrue(
            (new UniqueLock(Cache::store()))->acquire($stuckJob),
            'Failed to simulate a stuck unique lock.'
        );

        Queue::fake([TranscribeCallJob::class]);

        $this->actingAs($user)
            ->postJson('/api/calls/reanalyze', [
                'call_ids' => [$call->id],
            ])
            ->assertOk()
            ->assertJsonPath('queued_count', 1);

        Queue::assertPushed(TranscribeCallJob::class, 1);
        $this->assertSame(Call::STATUS_PENDING, $call->fresh()->status);
        $this->assertNull($call->fresh()->error_message);
    }
}
