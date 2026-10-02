<?php

namespace App\Console\Commands;

use App\Exceptions\TranscriptionFailedException;
use App\Services\WhisperTranscriptionService;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;

#[Signature('test:transcription {path : Absolute or relative path to the audio file} {--language=az}')]
#[Description('Transcribe an audio file and print text, segments, and speaker labels')]
class TestTranscription extends Command
{
    public function handle(WhisperTranscriptionService $whisper): int
    {
        $path = $this->argument('path');

        if (! is_file($path)) {
            $this->error("Audio file not found: {$path}");

            return self::FAILURE;
        }

        $absolutePath = realpath($path);

        if ($absolutePath === false) {
            $this->error("Could not resolve path: {$path}");

            return self::FAILURE;
        }

        try {
            $result = $whisper->transcribe($absolutePath, (string) $this->option('language'));
        } catch (TranscriptionFailedException $exception) {
            $this->error($exception->getMessage());

            return self::FAILURE;
        }

        $this->line('Text: '.$result['text']);
        $this->line('Segments: '.count($result['segments']));
        $this->newLine();

        foreach ($result['segments'] as $segment) {
            $start = isset($segment['start']) ? number_format($segment['start'], 2) : '?';
            $end = isset($segment['end']) ? number_format($segment['end'], 2) : '?';
            $speaker = $segment['speaker'] ?? 'unknown';

            $this->line("[{$start}-{$end}] {$speaker}: {$segment['text']}");
        }

        return self::SUCCESS;
    }
}
