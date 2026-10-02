<?php

namespace App\Services\Analysis;

use App\Contracts\AnalysisProvider;
use App\Services\WhisperTranscriptionService;

class GeminiAnalysisProvider implements AnalysisProvider
{
    public function __construct(private WhisperTranscriptionService $whisper) {}

    public function name(): string
    {
        return 'gemini';
    }

    public function model(): string
    {
        return (string) config('whisper.gemini_model', 'gemini-3.6-flash');
    }

    public function analyze(string $transcript, array $segments): array
    {
        return $this->whisper->analyzeCallQuality($transcript);
    }
}
