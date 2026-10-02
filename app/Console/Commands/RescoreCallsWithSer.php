<?php

namespace App\Console\Commands;

use App\Jobs\RescoreCallWithSerJob;
use App\Models\Call;
use App\Services\SerScoringService;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;
use Illuminate\Database\Eloquent\Builder;

#[Signature('calls:rescore-with-ser
    {--all : Queue every completed call}
    {--since= : Queue completed calls created on or after YYYY-MM-DD}
    {--call-id= : Queue a single call ID}')]
#[Description('Queue acoustic SER re-scoring for past calls (append-only ser_scores history)')]
class RescoreCallsWithSer extends Command
{
    public function handle(SerScoringService $scoring): int
    {
        $all = (bool) $this->option('all');
        $since = $this->option('since');
        $callId = $this->option('call-id');

        $filters = array_filter([
            $all ? 'all' : null,
            is_string($since) && $since !== '' ? 'since' : null,
            is_numeric($callId) ? 'call-id' : null,
        ]);

        if (count($filters) !== 1) {
            $this->error('Specify exactly one of: --all, --since=YYYY-MM-DD, or --call-id=ID.');

            return self::FAILURE;
        }

        if (! $scoring->isHealthy()) {
            $serviceUrl = rtrim((string) config('ser.service_url'), '/');
            $this->error("SER inference service is not reachable at {$serviceUrl}/health.");
            $this->line('Start it with: cd python/ser_service && uvicorn app:app --host 0.0.0.0 --port 8001');

            return self::FAILURE;
        }

        $query = $this->callsQuery($all, is_string($since) ? $since : null, $callId);

        $queued = 0;

        $query->orderBy('id')->chunkById(100, function ($calls) use (&$queued): void {
            foreach ($calls as $call) {
                RescoreCallWithSerJob::dispatch($call);
                $queued++;
            }
        });

        if ($queued === 0) {
            $this->warn('No matching calls to queue.');

            return self::SUCCESS;
        }

        $this->info("Queued {$queued} call(s) for SER re-scoring.");
        $this->line('Ensure a queue worker is running: php artisan queue:work');

        return self::SUCCESS;
    }

    private function callsQuery(bool $all, ?string $since, mixed $callId): Builder
    {
        $query = Call::query()->where('status', Call::STATUS_COMPLETED);

        if (is_numeric($callId)) {
            return $query->whereKey((int) $callId);
        }

        if ($since !== null && $since !== '') {
            return $query->whereDate('created_at', '>=', $since);
        }

        if ($all) {
            return $query;
        }

        return $query->whereRaw('1 = 0');
    }
}
