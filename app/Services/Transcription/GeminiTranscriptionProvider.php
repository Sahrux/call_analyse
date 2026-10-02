<?php

namespace App\Services\Transcription;

use App\Contracts\TranscriptionProvider;
use App\Services\WhisperTranscriptionService;

class GeminiTranscriptionProvider implements TranscriptionProvider
{
    public function __construct(private WhisperTranscriptionService $whisper) {}

    public function name(): string
    {
        return 'gemini';
    }

    public function model(): string
    {
        return (string) config('whisper.gemini_transcription_model', 'gemini-3.5-transcribe');
    }

    public function transcribe(string $absoluteAudioPath, string $language): array
    {
        return $this->whisper->transcribe($absoluteAudioPath, $language);
    }
}
