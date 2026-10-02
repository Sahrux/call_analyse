<?php

namespace App\Services;

use App\Models\Call;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Process;
use Illuminate\Support\Facades\Storage;

class AudioClipExtractionService
{
    /**
     * Extract a temporary audio clip for a transcript segment.
     *
     * @param  array{start?: mixed, end?: mixed}  $segment
     * @return string|null Absolute path to the temp clip, or null on failure / invalid timing.
     */
    public function extractSegmentClip(Call $call, array $segment): ?string
    {
        if ($call->audio_path === null) {
            Log::warning('Audio clip extraction skipped: call has no audio path.', [
                'call_id' => $call->id,
            ]);

            return null;
        }

        $disk = Storage::disk('public');

        if (! $disk->exists($call->audio_path)) {
            Log::warning('Audio clip extraction skipped: audio file missing.', [
                'call_id' => $call->id,
                'audio_path' => $call->audio_path,
            ]);

            return null;
        }

        $start = isset($segment['start']) ? (float) $segment['start'] : null;
        $end = isset($segment['end']) ? (float) $segment['end'] : null;

        if ($start === null || $end === null || $end <= $start) {
            Log::warning('Audio clip extraction skipped: invalid segment timing.', [
                'call_id' => $call->id,
                'start' => $segment['start'] ?? null,
                'end' => $segment['end'] ?? null,
            ]);

            return null;
        }

        $extension = pathinfo($call->audio_path, PATHINFO_EXTENSION) ?: 'wav';
        $clipPath = sys_get_temp_dir().DIRECTORY_SEPARATOR.'ser-clip-'.uniqid((string) $call->id.'_', true).'.'.$extension;
        $sourcePath = $disk->path($call->audio_path);

        if (! $this->extractClip($sourcePath, $start, $end, $clipPath)) {
            Log::warning('Audio clip extraction failed via ffmpeg.', [
                'call_id' => $call->id,
                'source_path' => $sourcePath,
                'start' => $start,
                'end' => $end,
            ]);

            if (is_file($clipPath)) {
                @unlink($clipPath);
            }

            return null;
        }

        return $clipPath;
    }

    /**
     * Extract an audio clip with ffmpeg (stream copy, then PCM re-encode fallback).
     */
    public function extractClip(string $audioPath, float $start, float $end, string $clipPath): bool
    {
        $ffmpeg = (string) config('whisper.ffmpeg');

        $copyResult = Process::timeout(120)->run([
            $ffmpeg,
            '-y',
            '-i', $audioPath,
            '-ss', (string) $start,
            '-to', (string) $end,
            '-c', 'copy',
            $clipPath,
        ]);

        if ($copyResult->successful() && is_file($clipPath)) {
            return true;
        }

        $encodeResult = Process::timeout(120)->run([
            $ffmpeg,
            '-y',
            '-i', $audioPath,
            '-ss', (string) $start,
            '-to', (string) $end,
            '-c:a', 'pcm_s16le',
            $clipPath,
        ]);

        return $encodeResult->successful() && is_file($clipPath);
    }
}
