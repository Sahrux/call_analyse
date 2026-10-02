<?php

namespace Tests\Unit;

use App\Exceptions\TranscriptionFailedException;
use App\Services\Transcription\ElevenLabsTranscriptionProvider;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class ElevenLabsTranscriptionProviderTest extends TestCase
{
    private string $audioPath;

    protected function setUp(): void
    {
        parent::setUp();

        config([
            'services.elevenlabs.key' => 'test-elevenlabs-key',
            'services.elevenlabs.stt_model' => 'scribe_v1',
        ]);

        $this->audioPath = tempnam(sys_get_temp_dir(), 'call');
        file_put_contents($this->audioPath, 'fake-audio');
    }

    protected function tearDown(): void
    {
        if (is_file($this->audioPath)) {
            unlink($this->audioPath);
        }

        parent::tearDown();
    }

    public function test_groups_words_into_segments_and_maps_the_first_speaker_to_operator(): void
    {
        Http::preventStrayRequests();
        Http::fake([
            'https://api.elevenlabs.io/v1/speech-to-text' => Http::response([
                'language_code' => 'az',
                'text' => 'Salam buyurun Necəsən',
                'words' => [
                    ['text' => 'Necəsən', 'start' => 1.2, 'end' => 1.8, 'type' => 'word', 'speaker_id' => 'speaker_0'],
                    ['text' => 'Salam', 'start' => 0.1, 'end' => 0.4, 'type' => 'word', 'speaker_id' => 'speaker_1'],
                    ['text' => ' ', 'start' => 0.4, 'end' => 0.42, 'type' => 'spacing', 'speaker_id' => 'speaker_1'],
                    ['text' => '!', 'start' => 0.9, 'end' => 0.95, 'type' => 'punctuation', 'speaker_id' => 'speaker_1'],
                    ['text' => 'buyurun', 'start' => 0.5, 'end' => 0.9, 'type' => 'word', 'speaker_id' => 'speaker_1'],
                    ['text' => '(gülür)', 'start' => 1.8, 'end' => 2.0, 'type' => 'audio_event', 'speaker_id' => 'speaker_0'],
                ],
            ]),
        ]);

        $result = app(ElevenLabsTranscriptionProvider::class)->transcribe($this->audioPath, 'en');

        $this->assertSame('Salam buyurun Necəsən', $result['text']);
        $this->assertSame('scribe_v1', $result['model']);
        $this->assertSame('az-AZ', $result['whisper_language']);
        $this->assertSame(1, $result['duration_seconds']);
        $this->assertSame([
            [
                'start' => 0.1,
                'end' => 0.9,
                'text' => 'Salam buyurun',
                'speaker' => 'operator',
            ],
            [
                'start' => 1.2,
                'end' => 1.8,
                'text' => 'Necəsən',
                'speaker' => 'musteri',
            ],
        ], $result['segments']);

        Http::assertSent(function (Request $request): bool {
            $body = $request->body();

            return $request->url() === 'https://api.elevenlabs.io/v1/speech-to-text'
                && $request->header('xi-api-key')[0] === 'test-elevenlabs-key'
                && str_contains($body, 'scribe_v1')
                && str_contains($body, 'name="diarize"')
                && str_contains($body, 'name="timestamps_granularity"')
                && str_contains($body, 'word')
                && str_contains($body, 'name="tag_audio_events"')
                && ! str_contains($body, 'language_code');
        });
    }

    public function test_additional_speakers_fold_into_musteri_and_language_uses_the_stored_format(): void
    {
        Http::preventStrayRequests();
        Http::fake([
            'https://api.elevenlabs.io/v1/speech-to-text' => Http::response([
                'language_code' => 'ru',
                'text' => 'Salam Privet Eşitdim',
                'words' => [
                    ['text' => 'Salam', 'start' => 0.0, 'end' => 0.4, 'type' => 'word', 'speaker_id' => 'speaker_0'],
                    ['text' => 'Privet', 'start' => 0.5, 'end' => 1.0, 'type' => 'word', 'speaker_id' => 'speaker_1'],
                    ['text' => 'Eşitdim', 'start' => 1.2, 'end' => 1.7, 'type' => 'word', 'speaker_id' => 'speaker_2'],
                ],
            ]),
        ]);

        $result = app(ElevenLabsTranscriptionProvider::class)->transcribe($this->audioPath, 'az');

        $this->assertSame('ru-RU', $result['whisper_language']);
        $this->assertSame('operator', $result['segments'][0]['speaker']);
        $this->assertSame('musteri', $result['segments'][1]['speaker']);
        $this->assertSame('musteri', $result['segments'][2]['speaker']);
        $this->assertSame('Privet', $result['segments'][1]['text']);
        $this->assertSame('Eşitdim', $result['segments'][2]['text']);
    }

    public function test_non_success_response_throws_the_response_message(): void
    {
        Http::preventStrayRequests();
        Http::fake([
            'https://api.elevenlabs.io/v1/speech-to-text' => Http::response([
                'detail' => ['message' => 'Invalid API key'],
            ], 401),
        ]);

        $this->expectException(TranscriptionFailedException::class);
        $this->expectExceptionMessage('Invalid API key');

        app(ElevenLabsTranscriptionProvider::class)->transcribe($this->audioPath, 'az');
    }

    public function test_missing_api_key_throws_before_a_request(): void
    {
        config(['services.elevenlabs.key' => '']);
        Http::preventStrayRequests();
        Http::fake();

        $this->expectException(TranscriptionFailedException::class);
        $this->expectExceptionMessage('ELEVENLABS_API_KEY təyin edilməyib');

        app(ElevenLabsTranscriptionProvider::class)->transcribe($this->audioPath, 'az');
    }
}
