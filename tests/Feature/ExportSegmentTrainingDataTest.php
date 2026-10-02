<?php

namespace Tests\Feature;

use App\Models\Call;
use App\Models\CallAnalysis;
use App\Services\SegmentTrainingDataExportService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class ExportSegmentTrainingDataTest extends TestCase
{
    use RefreshDatabase;

    public function test_export_writes_manifest_and_clip_entries(): void
    {
        Storage::fake('public');

        $audioPath = 'calls/audio/test.wav';
        Storage::disk('public')->put($audioPath, 'fake-audio-content');

        $call = Call::factory()->create([
            'status' => Call::STATUS_COMPLETED,
            'audio_path' => $audioPath,
            'called_at' => now(),
        ]);

        CallAnalysis::query()->create([
            'call_id' => $call->id,
            'transcript' => 'Buyurun. Salam.',
            'transcript_segments' => [
                [
                    'speaker' => 'operator',
                    'start' => 0.0,
                    'end' => 1.2,
                    'text' => 'Buyurun.',
                    'courtesy_score' => 90,
                    'rudeness_score' => 5,
                    'emotional_tone_score' => 88,
                ],
                [
                    'speaker' => 'musteri',
                    'start' => 1.5,
                    'end' => 1.8,
                    'text' => 'Salam.',
                    'courtesy_score' => 70,
                    'rudeness_score' => 10,
                    'emotional_tone_score' => 65,
                ],
                [
                    'speaker' => 'musteri',
                    'start' => 2.0,
                    'end' => 2.2,
                    'text' => 'Hm.',
                ],
            ],
        ]);

        $outputDir = sys_get_temp_dir().DIRECTORY_SEPARATOR.'segment-export-'.uniqid('', true);

        try {
            $summary = app(SegmentTrainingDataExportService::class)->export(
                from: null,
                to: null,
                outputDir: $outputDir,
                minDurationSeconds: 0.5,
                clipExtractor: static function (string $audioPath, float $start, float $end, string $clipPath): bool {
                    File::put($clipPath, 'clip');

                    return true;
                },
            );

            $this->assertSame(1, $summary['calls_processed']);
            $this->assertSame(1, $summary['clips_exported']);
            $this->assertSame(2, $summary['clips_skipped']);
            $this->assertSame(1.2, $summary['exported_audio_seconds']);

            $manifestPath = $outputDir.DIRECTORY_SEPARATOR.'manifest.jsonl';
            $this->assertFileExists($manifestPath);
            $this->assertFileExists($outputDir.DIRECTORY_SEPARATOR.sprintf('%d_0.wav', $call->id));

            $lines = array_values(array_filter(explode(PHP_EOL, File::get($manifestPath))));
            $this->assertCount(1, $lines);

            $entry = json_decode($lines[0], true, flags: JSON_THROW_ON_ERROR);

            $this->assertSame(sprintf('%d_0.wav', $call->id), $entry['clip_path']);
            $this->assertSame($call->id, $entry['call_id']);
            $this->assertSame(0, $entry['segment_index']);
            $this->assertSame('operator', $entry['speaker']);
            $this->assertSame(1.2, $entry['duration_seconds']);
            $this->assertSame(90, $entry['courtesy_score']);
            $this->assertSame(88, $entry['emotional_tone_score']);
            $this->assertNull($entry['manual_courtesy_score']);
            $this->assertFalse($entry['has_manual_review']);
        } finally {
            File::deleteDirectory($outputDir);
        }
    }

    public function test_export_manifest_includes_manual_scores_and_has_manual_review_flag(): void
    {
        Storage::fake('public');

        $audioPath = 'calls/audio/test.wav';
        Storage::disk('public')->put($audioPath, 'fake-audio-content');

        $call = Call::factory()->create([
            'status' => Call::STATUS_COMPLETED,
            'audio_path' => $audioPath,
            'called_at' => now(),
        ]);

        CallAnalysis::query()->create([
            'call_id' => $call->id,
            'transcript' => 'Buyurun. Salam.',
            'transcript_segments' => [
                [
                    'speaker' => 'operator',
                    'start' => 0.0,
                    'end' => 1.2,
                    'text' => 'Buyurun.',
                    'courtesy_score' => 90,
                    'rudeness_score' => 5,
                    'emotional_tone_score' => 88,
                    'manual_courtesy_score' => 95,
                    'manual_rudeness_score' => 3,
                    'manual_emotional_tone_score' => 92,
                    'manual_reviewed' => true,
                    'manual_reviewed_by' => 7,
                ],
            ],
        ]);

        $outputDir = sys_get_temp_dir().DIRECTORY_SEPARATOR.'segment-export-manual-'.uniqid('', true);

        try {
            app(SegmentTrainingDataExportService::class)->export(
                from: null,
                to: null,
                outputDir: $outputDir,
                clipExtractor: static function (string $audioPath, float $start, float $end, string $clipPath): bool {
                    File::put($clipPath, 'clip');

                    return true;
                },
            );

            $manifestPath = $outputDir.DIRECTORY_SEPARATOR.'manifest.jsonl';
            $entry = json_decode(
                array_values(array_filter(explode(PHP_EOL, File::get($manifestPath))))[0],
                true,
                flags: JSON_THROW_ON_ERROR,
            );

            $this->assertTrue($entry['has_manual_review']);
            $this->assertSame(90, $entry['courtesy_score']);
            $this->assertSame(5, $entry['rudeness_score']);
            $this->assertSame(88, $entry['emotional_tone_score']);
            $this->assertSame(95, $entry['manual_courtesy_score']);
            $this->assertSame(3, $entry['manual_rudeness_score']);
            $this->assertSame(92, $entry['manual_emotional_tone_score']);
        } finally {
            File::deleteDirectory($outputDir);
        }
    }

    public function test_export_uses_manual_scores_when_auto_scores_are_missing(): void
    {
        Storage::fake('public');

        $audioPath = 'calls/audio/test.wav';
        Storage::disk('public')->put($audioPath, 'fake-audio-content');

        $call = Call::factory()->create([
            'status' => Call::STATUS_COMPLETED,
            'audio_path' => $audioPath,
            'called_at' => now(),
        ]);

        CallAnalysis::query()->create([
            'call_id' => $call->id,
            'transcript' => 'Buyurun.',
            'transcript_segments' => [
                [
                    'speaker' => 'operator',
                    'start' => 0.0,
                    'end' => 1.2,
                    'text' => 'Buyurun.',
                    'manual_courtesy_score' => 95,
                    'manual_rudeness_score' => 3,
                    'manual_emotional_tone_score' => 92,
                    'manual_reviewed' => true,
                ],
            ],
        ]);

        $outputDir = sys_get_temp_dir().DIRECTORY_SEPARATOR.'segment-export-manual-fallback-'.uniqid('', true);

        try {
            $summary = app(SegmentTrainingDataExportService::class)->export(
                from: null,
                to: null,
                outputDir: $outputDir,
                clipExtractor: static function (string $audioPath, float $start, float $end, string $clipPath): bool {
                    File::put($clipPath, 'clip');

                    return true;
                },
            );

            $this->assertSame(1, $summary['clips_exported']);
            $this->assertSame(0, $summary['clips_skipped']);

            $manifestPath = $outputDir.DIRECTORY_SEPARATOR.'manifest.jsonl';
            $entry = json_decode(
                array_values(array_filter(explode(PHP_EOL, File::get($manifestPath))))[0],
                true,
                flags: JSON_THROW_ON_ERROR,
            );

            $this->assertSame(95, $entry['courtesy_score']);
            $this->assertSame(3, $entry['rudeness_score']);
            $this->assertSame(92, $entry['emotional_tone_score']);
            $this->assertSame(95, $entry['manual_courtesy_score']);
            $this->assertSame(3, $entry['manual_rudeness_score']);
            $this->assertSame(92, $entry['manual_emotional_tone_score']);
            $this->assertTrue($entry['has_manual_review']);
        } finally {
            File::deleteDirectory($outputDir);
        }
    }

    public function test_export_command_prints_summary(): void
    {
        Storage::fake('public');

        $audioPath = 'calls/audio/test.wav';
        Storage::disk('public')->put($audioPath, 'fake-audio-content');

        $call = Call::factory()->create([
            'status' => Call::STATUS_COMPLETED,
            'audio_path' => $audioPath,
            'called_at' => now(),
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
        ]);

        $outputDir = sys_get_temp_dir().DIRECTORY_SEPARATOR.'segment-export-cmd-'.uniqid('', true);

        $this->mock(SegmentTrainingDataExportService::class, function ($mock): void {
            $mock->shouldReceive('export')
                ->once()
                ->andReturn([
                    'calls_processed' => 1,
                    'clips_exported' => 1,
                    'clips_skipped' => 0,
                    'exported_audio_seconds' => 1.0,
                ]);
        });

        try {
            $exitCode = Artisan::call('calls:export-segment-training-data', [
                '--output-dir' => $outputDir,
            ]);

            $this->assertSame(0, $exitCode);
            $this->assertStringContainsString('Segment clips exported: 1', Artisan::output());
        } finally {
            if (File::isDirectory($outputDir)) {
                File::deleteDirectory($outputDir);
            }
        }
    }
}
