<?php

namespace Tests\Feature;

use App\Exceptions\TranscriptionFailedException;
use App\Jobs\AnalyzeCallJob;
use App\Jobs\TranscribeCallJob;
use App\Models\Call;
use App\Models\CallAnalysis;
use App\Models\Setting;
use App\Services\AiProviderResolver;
use App\Services\CallScoringService;
use App\Services\WhisperTranscriptionService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class CallAiProviderTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        config([
            'whisper.gemini_transcription_model' => 'gemini-3.5-transcribe',
            'whisper.gemini_model' => 'gemini-3.6-flash',
            'services.elevenlabs.key' => 'test-elevenlabs-key',
            'services.elevenlabs.stt_model' => 'scribe_v1',
            'services.openai.key' => 'test-openai-key',
            'services.openai.analysis_model' => 'gpt-6-luna',
        ]);
    }

    public function test_transcription_job_stores_the_provider_that_ran(): void
    {
        Storage::fake('public');
        Storage::disk('public')->put('calls/audio/sample.mp3', 'fake-audio');
        Setting::setValue('transcription_provider', 'gemini');

        $call = Call::factory()->create([
            'audio_path' => 'calls/audio/sample.mp3',
            'language' => 'az',
            'status' => Call::STATUS_PENDING,
            'analysis_provider' => 'openai',
            'analysis_model' => 'gpt-6-luna',
        ]);

        $this->mock(WhisperTranscriptionService::class, function ($mock): void {
            $mock->shouldReceive('transcribe')
                ->once()
                ->andReturn([
                    'text' => 'Salam',
                    'segments' => [
                        ['start' => 0.0, 'end' => 1.0, 'text' => 'Salam', 'speaker' => 'operator'],
                    ],
                    'duration_seconds' => 1,
                    'model' => 'some-other-model',
                    'whisper_language' => 'az-AZ',
                ]);
        });

        Bus::fake([AnalyzeCallJob::class]);

        (new TranscribeCallJob($call))->handle(app(AiProviderResolver::class));

        $call->refresh();
        $this->assertSame('gemini', $call->transcription_provider);
        $this->assertSame('gemini-3.5-transcribe', $call->transcription_model);
        $this->assertSame('openai', $call->analysis_provider);
        $this->assertSame('gpt-6-luna', $call->analysis_model);
        $this->assertSame('some-other-model', $call->analysis->raw_result['model']);
    }

    public function test_analysis_job_stores_the_provider_that_ran_and_leaves_transcription_columns(): void
    {
        Setting::setValue('analysis_provider', 'gemini');

        $call = Call::factory()->create([
            'language' => 'az',
            'status' => Call::STATUS_COMPLETED,
            'transcription_provider' => 'elevenlabs',
            'transcription_model' => 'scribe_v1',
        ]);

        CallAnalysis::query()->create([
            'call_id' => $call->id,
            'transcript' => 'Salam, necəsiniz?',
            'transcript_segments' => [
                ['speaker' => 'operator', 'start' => 0.0, 'end' => 1.0, 'text' => 'Salam, necəsiniz?'],
            ],
        ]);

        $this->mock(WhisperTranscriptionService::class, function ($mock): void {
            $mock->shouldReceive('analyzeCallQuality')
                ->once()
                ->andReturn([
                    'courtesy_score' => 80,
                    'rudeness_score' => 10,
                    'emotional_tone_score' => 20,
                    'inappropriate_expressions' => [],
                ]);
        });

        (new AnalyzeCallJob($call))->handle(
            app(CallScoringService::class),
            app(AiProviderResolver::class),
        );

        $call->refresh();
        $this->assertSame('elevenlabs', $call->transcription_provider);
        $this->assertSame('scribe_v1', $call->transcription_model);
        $this->assertSame('gemini', $call->analysis_provider);
        $this->assertSame('gemini-3.6-flash', $call->analysis_model);
        $this->assertSame(80, $call->analysis->courtesy_score);
    }

    public function test_transcription_rerun_updates_only_transcription_columns(): void
    {
        Storage::fake('public');
        Storage::disk('public')->put('calls/audio/sample.mp3', 'fake-audio');
        Setting::setValue('transcription_provider', 'elevenlabs');
        Http::preventStrayRequests();
        Http::fake([
            'https://api.elevenlabs.io/v1/speech-to-text' => Http::response([
                'language_code' => 'en',
                'text' => 'Hello',
                'words' => [
                    ['text' => 'Hello', 'start' => 0.0, 'end' => 0.8, 'type' => 'word', 'speaker_id' => 'speaker_0'],
                ],
            ]),
        ]);

        $call = Call::factory()->create([
            'audio_path' => 'calls/audio/sample.mp3',
            'language' => 'az',
            'status' => Call::STATUS_COMPLETED,
            'transcription_provider' => 'gemini',
            'transcription_model' => 'gemini-3.5-transcribe',
            'analysis_provider' => 'openai',
            'analysis_model' => 'gpt-6-luna',
        ]);

        CallAnalysis::query()->create([
            'call_id' => $call->id,
            'transcript' => 'Köhnə',
            'courtesy_score' => 10,
        ]);

        Bus::fake([AnalyzeCallJob::class]);

        (new TranscribeCallJob($call))->handle(app(AiProviderResolver::class));

        $call->refresh();
        $this->assertSame('elevenlabs', $call->transcription_provider);
        $this->assertSame('scribe_v1', $call->transcription_model);
        $this->assertSame('openai', $call->analysis_provider);
        $this->assertSame('gpt-6-luna', $call->analysis_model);
        $this->assertSame('Hello', $call->analysis->transcript);
        $this->assertSame(10, $call->analysis->courtesy_score);

        Setting::setValue('transcription_provider', 'gemini');
        $call->refresh();
        $this->assertSame('elevenlabs', $call->transcription_provider);
        $this->assertSame('scribe_v1', $call->transcription_model);
    }

    public function test_analysis_rerun_updates_only_analysis_columns(): void
    {
        foreach (CallScoringService::WEIGHT_DEFAULTS as $key => $value) {
            Setting::setValue($key, $value, 'scoring_params');
        }

        Setting::setValue('score_gamma', '1.5', 'scoring_params');
        Setting::setValue('analysis_provider', 'openai');
        Http::preventStrayRequests();
        Http::fake([
            'https://api.openai.com/v1/responses' => Http::response([
                'output' => [
                    [
                        'type' => 'message',
                        'content' => [
                            [
                                'type' => 'output_text',
                                'text' => '{"courtesy_score":70,"rudeness_score":60,"emotional_tone_score":65,"inappropriate_expressions":[{"speaker":"musteri","text":"zibil"}]}',
                            ],
                        ],
                    ],
                ],
            ]),
        ]);

        $call = Call::factory()->create([
            'language' => 'az',
            'status' => Call::STATUS_COMPLETED,
            'transcription_provider' => 'elevenlabs',
            'transcription_model' => 'scribe_v1',
            'analysis_provider' => 'gemini',
            'analysis_model' => 'gemini-3.6-flash',
        ]);

        CallAnalysis::query()->create([
            'call_id' => $call->id,
            'transcript' => 'Salam. Bu servis zibil.',
            'transcript_segments' => [
                ['speaker' => 'operator', 'start' => 0.0, 'end' => 1.0, 'text' => 'Salam'],
                ['speaker' => 'musteri', 'start' => 1.2, 'end' => 2.4, 'text' => 'Bu servis zibil'],
            ],
            'buyer_potential_score' => null,
        ]);

        (new AnalyzeCallJob($call))->handle(
            app(CallScoringService::class),
            app(AiProviderResolver::class),
        );

        $call->refresh();
        $analysis = $call->analysis;
        $this->assertSame('elevenlabs', $call->transcription_provider);
        $this->assertSame('scribe_v1', $call->transcription_model);
        $this->assertSame('openai', $call->analysis_provider);
        $this->assertSame('gpt-6-luna', $call->analysis_model);
        $this->assertSame(70, $analysis->courtesy_score);
        $this->assertSame(60, $analysis->rudeness_score);
        $this->assertSame(65, $analysis->emotional_tone_score);
        $this->assertSame(37, $analysis->total_score);
        $this->assertSame(0, $analysis->operator_inappropriate_words_count);
        $this->assertSame(1, $analysis->customer_inappropriate_words_count);
        $this->assertNull($analysis->buyer_potential_score);
        $this->assertSame([
            ['speaker' => 'musteri', 'text' => 'zibil'],
        ], $analysis->inappropriate_expressions);

        Setting::setValue('analysis_provider', 'gemini');
        $call->refresh();
        $this->assertSame('openai', $call->analysis_provider);
        $this->assertSame('gpt-6-luna', $call->analysis_model);
    }

    public function test_failed_transcription_does_not_store_provider_columns(): void
    {
        Storage::fake('public');
        Storage::disk('public')->put('calls/audio/sample.mp3', 'fake-audio');
        Setting::setValue('transcription_provider', 'elevenlabs');
        Http::preventStrayRequests();
        Http::fake([
            'https://api.elevenlabs.io/v1/speech-to-text' => Http::response([
                'detail' => ['message' => 'upstream timeout'],
            ], 504),
        ]);

        $call = Call::factory()->create([
            'audio_path' => 'calls/audio/sample.mp3',
            'status' => Call::STATUS_PENDING,
        ]);

        $job = new TranscribeCallJob($call);

        try {
            $job->handle(app(AiProviderResolver::class));
            $this->fail('Expected transcription to fail.');
        } catch (TranscriptionFailedException $exception) {
            $job->failed($exception);
        }

        $call->refresh();
        $this->assertSame(Call::STATUS_FAILED, $call->status);
        $this->assertSame('upstream timeout', $call->error_message);
        $this->assertNull($call->transcription_provider);
        $this->assertNull($call->transcription_model);
        $this->assertNull($call->analysis_provider);
        $this->assertNull($call->analysis_model);
    }
}
