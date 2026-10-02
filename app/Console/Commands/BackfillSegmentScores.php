<?php

namespace App\Console\Commands;

use App\Services\CallSegmentScoreBackfillService;
use App\Services\WhisperTranscriptionService;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;
use Throwable;

#[Signature('calls:backfill-segment-scores
    {--call= : Backfill a single call ID}
    {--dry-run : List calls that would be updated without calling Gemini}')]
#[Description('Backfill per-segment Gemini quality scores without re-transcribing or re-scoring the call')]
class BackfillSegmentScores extends Command
{
    public function handle(
        CallSegmentScoreBackfillService $backfill,
        WhisperTranscriptionService $whisper,
    ): int {
        $callId = $this->option('call');
        $dryRun = (bool) $this->option('dry-run');

        $calls = $backfill->callsNeedingSegmentScores(
            is_numeric($callId) ? (int) $callId : null,
        );

        if ($calls->isEmpty()) {
            $this->info('No calls need segment-level score backfill.');

            return self::SUCCESS;
        }

        $this->info('Calls needing segment scores: '.$calls->count());

        if ($dryRun) {
            foreach ($calls as $call) {
                $segmentCount = count($call->analysis?->transcript_segments ?? []);
                $this->line("  - Call #{$call->id} ({$segmentCount} segments)");
            }

            return self::SUCCESS;
        }

        $processed = 0;
        $failed = 0;

        foreach ($calls as $call) {
            $segmentCount = count($call->analysis?->transcript_segments ?? []);
            $this->line("Backfilling call #{$call->id} ({$segmentCount} segments)...");

            try {
                $backfill->backfillCall($call, $whisper);
                $processed++;
                $this->info("  Saved segment scores for call #{$call->id}.");
            } catch (Throwable $exception) {
                $failed++;
                $this->error("  Failed for call #{$call->id}: {$exception->getMessage()}");
            }
        }

        $this->newLine();
        $this->info("Backfill complete. Processed: {$processed}, failed: {$failed}.");

        return $failed > 0 ? self::FAILURE : self::SUCCESS;
    }
}
