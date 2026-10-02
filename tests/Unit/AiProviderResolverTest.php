<?php

namespace Tests\Unit;

use App\Exceptions\TranscriptionFailedException;
use App\Models\Setting;
use App\Services\AiProviderResolver;
use App\Services\Analysis\GeminiAnalysisProvider;
use App\Services\Analysis\OpenAiAnalysisProvider;
use App\Services\Transcription\ElevenLabsTranscriptionProvider;
use App\Services\Transcription\GeminiTranscriptionProvider;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class AiProviderResolverTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        config([
            'whisper.gemini_transcription_model' => 'gemini-3.5-transcribe',
            'whisper.gemini_model' => 'gemini-3.6-flash',
            'services.elevenlabs.stt_model' => 'scribe_v1',
            'services.openai.analysis_model' => 'gpt-6-luna',
        ]);
    }

    public function test_transcription_defaults_to_gemini_when_the_setting_is_missing(): void
    {
        Setting::query()->where('key', AiProviderResolver::TRANSCRIPTION_SETTING)->delete();
        Setting::flushCache();

        $provider = app(AiProviderResolver::class)->transcription();

        $this->assertInstanceOf(GeminiTranscriptionProvider::class, $provider);
        $this->assertSame('gemini', $provider->name());
        $this->assertSame('gemini-3.5-transcribe', $provider->model());
    }

    public function test_analysis_defaults_to_gemini_when_the_setting_is_missing(): void
    {
        Setting::query()->where('key', AiProviderResolver::ANALYSIS_SETTING)->delete();
        Setting::flushCache();

        $provider = app(AiProviderResolver::class)->analysis();

        $this->assertInstanceOf(GeminiAnalysisProvider::class, $provider);
        $this->assertSame('gemini', $provider->name());
        $this->assertSame('gemini-3.6-flash', $provider->model());
    }

    public function test_transcription_setting_selects_elevenlabs(): void
    {
        Setting::setValue(AiProviderResolver::TRANSCRIPTION_SETTING, 'elevenlabs');

        $provider = app(AiProviderResolver::class)->transcription();

        $this->assertInstanceOf(ElevenLabsTranscriptionProvider::class, $provider);
        $this->assertSame('elevenlabs', $provider->name());
        $this->assertSame('scribe_v1', $provider->model());
    }

    public function test_analysis_setting_selects_openai(): void
    {
        Setting::setValue(AiProviderResolver::ANALYSIS_SETTING, 'openai');

        $provider = app(AiProviderResolver::class)->analysis();

        $this->assertInstanceOf(OpenAiAnalysisProvider::class, $provider);
        $this->assertSame('openai', $provider->name());
        $this->assertSame('gpt-6-luna', $provider->model());
    }

    public function test_unknown_transcription_provider_throws(): void
    {
        Setting::setValue(AiProviderResolver::TRANSCRIPTION_SETTING, 'whisper');

        $this->expectException(TranscriptionFailedException::class);
        $this->expectExceptionMessage('Naməlum transkript provayderi: whisper');

        app(AiProviderResolver::class)->transcription();
    }

    public function test_unknown_analysis_provider_throws(): void
    {
        Setting::setValue(AiProviderResolver::ANALYSIS_SETTING, 'claude');

        $this->expectException(TranscriptionFailedException::class);
        $this->expectExceptionMessage('Naməlum analiz provayderi: claude');

        app(AiProviderResolver::class)->analysis();
    }
}
