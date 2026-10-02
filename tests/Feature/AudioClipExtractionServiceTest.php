<?php

namespace Tests\Feature;

use App\Models\Call;
use App\Services\AudioClipExtractionService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Process\PendingProcess;
use Illuminate\Support\Facades\Process;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class AudioClipExtractionServiceTest extends TestCase
{
    use RefreshDatabase;

    public function test_extract_clip_uses_stream_copy_when_ffmpeg_succeeds(): void
    {
        Process::fake([
            '*' => function (PendingProcess $process) {
                $clipPath = $process->command[array_key_last($process->command)] ?? null;

                if (is_string($clipPath)) {
                    file_put_contents($clipPath, 'copied');
                }

                return Process::result();
            },
        ]);

        $output = sys_get_temp_dir().DIRECTORY_SEPARATOR.'clip-'.uniqid('', true).'.wav';

        try {
            $ok = app(AudioClipExtractionService::class)->extractClip(
                'C:\\audio\\source.wav',
                1.0,
                2.5,
                $output,
            );

            $this->assertTrue($ok);
            $this->assertFileExists($output);

            Process::assertRan(function (PendingProcess $process): bool {
                $command = $process->command;

                return in_array('-c', $command, true)
                    && in_array('copy', $command, true)
                    && in_array('-ss', $command, true)
                    && in_array('1', $command, true)
                    && in_array('-to', $command, true)
                    && in_array('2.5', $command, true);
            });
        } finally {
            if (is_file($output)) {
                unlink($output);
            }
        }
    }

    public function test_extract_segment_clip_returns_null_for_invalid_timing(): void
    {
        Storage::fake('public');
        Storage::disk('public')->put('calls/audio/sample.wav', 'fake-audio-bytes');

        $call = Call::factory()->create([
            'audio_path' => 'calls/audio/sample.wav',
        ]);

        $result = app(AudioClipExtractionService::class)->extractSegmentClip($call, [
            'start' => 2.0,
            'end' => 1.0,
        ]);

        $this->assertNull($result);
    }
}
