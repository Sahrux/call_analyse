<?php

namespace App\Services;

use App\Contracts\AnalysisProvider;
use App\Contracts\TranscriptionProvider;
use App\Exceptions\TranscriptionFailedException;
use App\Models\Setting;
use App\Services\Analysis\GeminiAnalysisProvider;
use App\Services\Analysis\OpenAiAnalysisProvider;
use App\Services\Transcription\ElevenLabsTranscriptionProvider;
use App\Services\Transcription\GeminiTranscriptionProvider;

class AiProviderResolver
{
    public const TRANSCRIPTION_SETTING = 'transcription_provider';

    public const ANALYSIS_SETTING = 'analysis_provider';

    public function transcription(): TranscriptionProvider
    {
        $name = (string) Setting::getValue(self::TRANSCRIPTION_SETTING, 'gemini');

        return match ($name) {
            'gemini' => app(GeminiTranscriptionProvider::class),
            'elevenlabs' => app(ElevenLabsTranscriptionProvider::class),
            default => throw new TranscriptionFailedException("Naməlum transkript provayderi: {$name}"),
        };
    }

    public function analysis(): AnalysisProvider
    {
        $name = (string) Setting::getValue(self::ANALYSIS_SETTING, 'gemini');

        return match ($name) {
            'gemini' => app(GeminiAnalysisProvider::class),
            'openai' => app(OpenAiAnalysisProvider::class),
            default => throw new TranscriptionFailedException("Naməlum analiz provayderi: {$name}"),
        };
    }
}
