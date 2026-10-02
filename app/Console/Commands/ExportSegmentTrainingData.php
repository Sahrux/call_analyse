<?php

namespace App\Console\Commands;

use App\Services\SegmentTrainingDataExportService;
use Carbon\Carbon;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;

#[Signature('calls:export-segment-training-data
    {--from= : Include calls on or after this date (Y-m-d)}
    {--to= : Include calls on or before this date (Y-m-d)}
    {--output-dir= : Directory for clips and manifest.jsonl}')]
#[Description('Export per-segment audio clips and Gemini weak labels for SER model training')]
class ExportSegmentTrainingData extends Command
{
    public function handle(SegmentTrainingDataExportService $exporter): int
    {
        $outputDir = $this->option('output-dir');

        if (! is_string($outputDir) || $outputDir === '') {
            $outputDir = storage_path('app/segment-training-data');
        }

        try {
            $from = $this->parseDateOption('from');
            $to = $this->parseDateOption('to');
        } catch (\InvalidArgumentException $exception) {
            $this->error($exception->getMessage());

            return self::FAILURE;
        }

        if ($from !== null && $to !== null && $from->greaterThan($to)) {
            $this->error('The --from date must be on or before the --to date.');

            return self::FAILURE;
        }

        $summary = $exporter->export($from, $to, $outputDir);

        $this->info('Segment training data export complete.');
        $this->line('Output directory: '.$outputDir);
        $this->line('Calls processed: '.$summary['calls_processed']);
        $this->line('Segment clips exported: '.$summary['clips_exported']);
        $this->line('Segments skipped: '.$summary['clips_skipped']);
        $this->line('Exported audio duration (seconds): '.$summary['exported_audio_seconds']);

        return self::SUCCESS;
    }

    private function parseDateOption(string $option): ?Carbon
    {
        $value = $this->option($option);

        if (! is_string($value) || $value === '') {
            return null;
        }

        try {
            return Carbon::parse($value)->startOfDay();
        } catch (\Throwable) {
            throw new \InvalidArgumentException("Invalid date for --{$option}: {$value}");
        }
    }
}
