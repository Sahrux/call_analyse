<?php

namespace Tests\Unit;

use App\Exceptions\TranscriptionFailedException;
use App\Services\WhisperTranscriptionService;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Sleep;
use Tests\TestCase;

class WhisperTranscriptionServiceTest extends TestCase
{
    public function test_transcribe_maps_gemini_interaction_response(): void
    {
        config([
            'whisper.gemini_api_key' => 'test-gemini-key',
            'whisper.gemini_transcription_model' => 'gemini-3.5-transcribe',
        ]);

        $audioPath = tempnam(sys_get_temp_dir(), 'audio');
        file_put_contents($audioPath, 'fake-audio');

        Http::fake([
            'generativelanguage.googleapis.com/upload/v1beta/files' => Http::response([
                'file' => [
                    'name' => 'files/test-file',
                    'uri' => 'https://generativelanguage.googleapis.com/v1beta/files/test-file',
                    'mimeType' => 'audio/mpeg',
                    'state' => 'ACTIVE',
                ],
            ]),
            'generativelanguage.googleapis.com/v1beta/interactions' => Http::response([
                'model' => 'gemini-3.5-transcribe',
                'steps' => [
                    [
                        'type' => 'model_output',
                        'content' => [
                            [
                                'type' => 'text',
                                'text' => ' Salam dünya ',
                                'annotations' => [
                                    [
                                        'type' => 'word_info',
                                        'text' => 'Salam',
                                        'start_offset' => '0s',
                                        'end_offset' => '0.5s',
                                        'speaker' => 'spk_1',
                                    ],
                                    [
                                        'type' => 'word_info',
                                        'text' => 'dünya',
                                        'start_offset' => '0.5s',
                                        'end_offset' => '1.2s',
                                        'speaker' => 'spk_1',
                                    ],
                                ],
                            ],
                        ],
                    ],
                ],
            ]),
        ]);

        $result = (new WhisperTranscriptionService)->transcribe($audioPath, 'az');

        $this->assertSame('Salam dünya', $result['text']);
        $this->assertSame([
            ['start' => 0.0, 'end' => 1.2, 'text' => 'Salam dünya', 'speaker' => 'operator'],
        ], $result['segments']);
        $this->assertSame(1, $result['duration_seconds']);
        $this->assertSame('gemini-3.5-transcribe', $result['model']);
        $this->assertSame('az-AZ', $result['whisper_language']);

        Http::assertSent(function ($request): bool {
            if ($request->url() !== 'https://generativelanguage.googleapis.com/upload/v1beta/files') {
                return false;
            }

            return $request->hasHeader('x-goog-api-key', 'test-gemini-key')
                && $request->hasFile('file', 'fake-audio');
        });

        Http::assertSent(function ($request): bool {
            if ($request->url() !== 'https://generativelanguage.googleapis.com/v1beta/interactions') {
                return false;
            }

            $payload = $request->data();

            return $request->hasHeader('x-goog-api-key', 'test-gemini-key')
                && ($payload['model'] ?? null) === 'gemini-3.5-transcribe'
                && ($payload['input'][0]['type'] ?? null) === 'text'
                && str_contains((string) ($payload['input'][0]['text'] ?? ''), 'call center agent')
                && ($payload['input'][1]['type'] ?? null) === 'audio'
                && ($payload['input'][1]['uri'] ?? null) === 'https://generativelanguage.googleapis.com/v1beta/files/test-file'
                && ($payload['generation_config']['transcription_config']['language_codes'] ?? null) === ['az-AZ']
                && ($payload['generation_config']['transcription_config']['mode']['type'] ?? null) === 'verbatim'
                && ($payload['generation_config']['transcription_config']['mode']['diarization_mode'] ?? null) === 'speaker'
                && ($payload['generation_config']['transcription_config']['mode']['timestamp_granularities'] ?? null) === ['word'];
        });

        @unlink($audioPath);
    }

    public function test_transcribe_merges_speaker_turns_and_normalizes_labels(): void
    {
        config([
            'whisper.gemini_api_key' => 'test-gemini-key',
            'whisper.gemini_transcription_model' => 'gemini-3.5-transcribe',
        ]);

        $audioPath = tempnam(sys_get_temp_dir(), 'audio');
        file_put_contents($audioPath, 'fake-audio');

        Http::fake([
            'generativelanguage.googleapis.com/upload/v1beta/files' => Http::response([
                'file' => [
                    'name' => 'files/test-file',
                    'uri' => 'https://generativelanguage.googleapis.com/v1beta/files/test-file',
                    'mimeType' => 'audio/mpeg',
                    'state' => 'ACTIVE',
                ],
            ]),
            'generativelanguage.googleapis.com/v1beta/interactions' => Http::response([
                'model' => 'gemini-3.5-transcribe',
                'steps' => [
                    [
                        'type' => 'model_output',
                        'content' => [
                            [
                                'type' => 'text',
                                'text' => 'Buyurun. Salam, Əli xanım.',
                                'annotations' => [
                                    [
                                        'type' => 'word_info',
                                        'text' => 'Buyurun.',
                                        'start_offset' => '0.6s',
                                        'end_offset' => '1.2s',
                                        'speaker' => 'spk:0',
                                    ],
                                    [
                                        'type' => 'word_info',
                                        'text' => 'Salam,',
                                        'start_offset' => '2.2s',
                                        'end_offset' => '2.4s',
                                        'speaker' => 'spk:1',
                                    ],
                                    [
                                        'type' => 'word_info',
                                        'text' => 'Əli',
                                        'start_offset' => '2.4s',
                                        'end_offset' => '2.7s',
                                        'speaker' => 'spk:1',
                                    ],
                                    [
                                        'type' => 'word_info',
                                        'text' => 'xanım.',
                                        'start_offset' => '2.7s',
                                        'end_offset' => '3.1s',
                                        'speaker' => 'spk:1',
                                    ],
                                ],
                            ],
                        ],
                    ],
                ],
            ]),
        ]);

        $result = (new WhisperTranscriptionService)->transcribe($audioPath, 'az');

        $this->assertSame([
            ['start' => 0.6, 'end' => 1.2, 'text' => 'Buyurun.', 'speaker' => 'operator'],
            ['start' => 2.2, 'end' => 3.1, 'text' => 'Salam, Əli xanım.', 'speaker' => 'musteri'],
        ], $result['segments']);

        @unlink($audioPath);
    }

    public function test_transcribe_does_not_merge_same_speaker_words_across_overlapping_other_speaker(): void
    {
        config([
            'whisper.gemini_api_key' => 'test-gemini-key',
            'whisper.gemini_transcription_model' => 'gemini-3.5-transcribe',
        ]);

        $audioPath = tempnam(sys_get_temp_dir(), 'audio');
        file_put_contents($audioPath, 'fake-audio');

        Http::fake([
            'generativelanguage.googleapis.com/upload/v1beta/files' => Http::response([
                'file' => [
                    'name' => 'files/test-file',
                    'uri' => 'https://generativelanguage.googleapis.com/v1beta/files/test-file',
                    'mimeType' => 'audio/mpeg',
                    'state' => 'ACTIVE',
                ],
            ]),
            'generativelanguage.googleapis.com/v1beta/interactions' => Http::response([
                'model' => 'gemini-3.5-transcribe',
                'steps' => [
                    [
                        'type' => 'model_output',
                        'content' => [
                            [
                                'type' => 'text',
                                'text' => 'Salam. Salam. necəsiniz?',
                                'annotations' => [
                                    [
                                        'type' => 'word_info',
                                        'text' => 'Salam.',
                                        'start_offset' => '0.0s',
                                        'end_offset' => '1.0s',
                                        'speaker' => 'spk:0',
                                    ],
                                    [
                                        'type' => 'word_info',
                                        'text' => 'Salam.',
                                        'start_offset' => '0.5s',
                                        'end_offset' => '1.5s',
                                        'speaker' => 'spk:1',
                                    ],
                                    [
                                        'type' => 'word_info',
                                        'text' => 'necəsiniz?',
                                        'start_offset' => '1.6s',
                                        'end_offset' => '2.5s',
                                        'speaker' => 'spk:0',
                                    ],
                                ],
                            ],
                        ],
                    ],
                ],
            ]),
        ]);

        $result = (new WhisperTranscriptionService)->transcribe($audioPath, 'az');

        $this->assertCount(3, $result['segments']);
        $this->assertSame('Salam.', $result['segments'][0]['text']);
        $this->assertSame('Salam.', $result['segments'][1]['text']);
        $this->assertSame('necəsiniz?', $result['segments'][2]['text']);

        @unlink($audioPath);
    }

    public function test_transcribe_throws_when_audio_file_is_missing(): void
    {
        config(['whisper.gemini_api_key' => 'test-gemini-key']);

        $this->expectException(TranscriptionFailedException::class);
        $this->expectExceptionMessage('Audio file not found');

        (new WhisperTranscriptionService)->transcribe('/path/does/not/exist.mp3', 'en');
    }

    public function test_transcribe_throws_when_gemini_api_key_is_missing(): void
    {
        config(['whisper.gemini_api_key' => null]);

        $audioPath = tempnam(sys_get_temp_dir(), 'audio');
        file_put_contents($audioPath, 'fake-audio');

        try {
            $this->expectException(TranscriptionFailedException::class);
            $this->expectExceptionMessage('Gemini API key is not configured.');

            (new WhisperTranscriptionService)->transcribe($audioPath, 'en');
        } finally {
            @unlink($audioPath);
        }
    }

    public function test_transcribe_throws_when_gemini_request_fails(): void
    {
        config(['whisper.gemini_api_key' => 'test-gemini-key']);

        $audioPath = tempnam(sys_get_temp_dir(), 'audio');
        file_put_contents($audioPath, 'fake-audio');

        Http::fake([
            'generativelanguage.googleapis.com/upload/v1beta/files' => Http::response([
                'error' => ['message' => 'Invalid API key'],
            ], 401),
        ]);

        try {
            $this->expectException(TranscriptionFailedException::class);
            $this->expectExceptionMessage('Invalid API key');

            (new WhisperTranscriptionService)->transcribe($audioPath, 'en');
        } finally {
            @unlink($audioPath);
        }
    }

    public function test_analyze_call_quality_parses_gemini_json_response(): void
    {
        config([
            'whisper.gemini_api_key' => 'test-gemini-key',
            'whisper.gemini_model' => 'gemini-3.6-flash',
        ]);

        Http::fake([
            'generativelanguage.googleapis.com/v1beta/interactions' => Http::response([
                'model' => 'gemini-3.6-flash',
                'steps' => [
                    [
                        'type' => 'thought',
                        'signature' => 'opaque-thought-signature',
                    ],
                    [
                        'type' => 'model_output',
                        'content' => [
                            [
                                'type' => 'text',
                                'text' => '{"courtesy_score":82,"rudeness_score":12,"emotional_tone_score":75}',
                            ],
                        ],
                    ],
                ],
            ]),
        ]);

        $result = (new WhisperTranscriptionService)->analyzeCallQuality('Thank you for calling, how may I help you?');

        $this->assertSame([
            'courtesy_score' => 82,
            'rudeness_score' => 12,
            'emotional_tone_score' => 75,
            'inappropriate_expressions' => null,
        ], $result);

        Http::assertSent(function ($request): bool {
            if ($request->url() !== 'https://generativelanguage.googleapis.com/v1beta/interactions') {
                return false;
            }

            $payload = $request->data();

            return $request->hasHeader('x-goog-api-key', 'test-gemini-key')
                && ($payload['model'] ?? null) === 'gemini-3.6-flash'
                && ($payload['input'][0]['type'] ?? null) === 'text'
                && ($payload['response_format']['type'] ?? null) === 'text'
                && ($payload['response_format']['mime_type'] ?? null) === 'application/json'
                && is_array($payload['response_format']['schema'] ?? null)
                && array_key_exists('inappropriate_expressions', $payload['response_format']['schema']['properties'] ?? [])
                && ! array_key_exists('aggression_score', $payload['response_format']['schema']['properties'] ?? [])
                && str_contains((string) ($payload['input'][0]['text'] ?? ''), 'Thank you for calling, how may I help you?')
                && str_contains((string) ($payload['input'][0]['text'] ?? ''), 'inappropriate_expressions')
                && str_contains((string) ($payload['input'][0]['text'] ?? ''), '"speaker"')
                && str_contains((string) ($payload['input'][0]['text'] ?? ''), '"text"')
                && str_contains((string) ($payload['input'][0]['text'] ?? ''), 'zibilə vurmaq')
                && str_contains((string) ($payload['input'][0]['text'] ?? ''), 'contemptuous language about the company/service IS inappropriate')
                && str_contains((string) ($payload['input'][0]['text'] ?? ''), 'the balance is being deducted incorrectly')
                && ! str_contains((string) ($payload['input'][0]['text'] ?? ''), 'aggression_score');
        });
    }

    public function test_analyze_call_quality_extracts_json_wrapped_in_prose(): void
    {
        config(['whisper.gemini_api_key' => 'test-gemini-key']);

        Http::fake([
            'generativelanguage.googleapis.com/v1beta/interactions' => Http::response([
                'steps' => [
                    [
                        'type' => 'model_output',
                        'content' => [
                            [
                                'type' => 'text',
                                'text' => "Here are the scores:\n{\"courtesy_score\":70,\"rudeness_score\":10,\"emotional_tone_score\":80}\nThanks.",
                            ],
                        ],
                    ],
                ],
            ]),
        ]);

        $result = (new WhisperTranscriptionService)->analyzeCallQuality('Hello');

        $this->assertSame([
            'courtesy_score' => 70,
            'rudeness_score' => 10,
            'emotional_tone_score' => 80,
            'inappropriate_expressions' => null,
        ], $result);
    }

    public function test_analyze_call_quality_strips_markdown_fences_from_response(): void
    {
        config(['whisper.gemini_api_key' => 'test-gemini-key']);

        Http::fake([
            'generativelanguage.googleapis.com/v1beta/interactions' => Http::response([
                'steps' => [
                    [
                        'content' => [
                            [
                                'type' => 'text',
                                'text' => "```json\n{\"courtesy_score\":60,\"rudeness_score\":40,\"emotional_tone_score\":55}\n```",
                            ],
                        ],
                    ],
                ],
            ]),
        ]);

        $result = (new WhisperTranscriptionService)->analyzeCallQuality('This is unacceptable.');

        $this->assertSame([
            'courtesy_score' => 60,
            'rudeness_score' => 40,
            'emotional_tone_score' => 55,
            'inappropriate_expressions' => null,
        ], $result);
    }

    public function test_analyze_call_quality_throws_when_gemini_api_key_is_missing(): void
    {
        config(['whisper.gemini_api_key' => null]);

        $this->expectException(TranscriptionFailedException::class);
        $this->expectExceptionMessage('Gemini API key is not configured.');

        (new WhisperTranscriptionService)->analyzeCallQuality('Hello');
    }

    public function test_analyze_call_quality_throws_when_gemini_request_fails(): void
    {
        config(['whisper.gemini_api_key' => 'test-gemini-key']);
        Sleep::fake();

        Http::fake([
            'generativelanguage.googleapis.com/v1beta/interactions' => Http::response([
                'error' => ['message' => 'Rate limit exceeded'],
            ], 429),
        ]);

        try {
            (new WhisperTranscriptionService)->analyzeCallQuality('Hello');
            $this->fail('Expected TranscriptionFailedException to be thrown.');
        } catch (TranscriptionFailedException $exception) {
            $this->assertSame('Rate limit exceeded', $exception->getMessage());
        }

        Http::assertSentCount(3);
        Sleep::assertSequence([
            Sleep::for(2)->seconds(),
            Sleep::for(5)->seconds(),
        ]);
    }

    public function test_analyze_call_quality_retries_transient_overload_then_succeeds(): void
    {
        config(['whisper.gemini_api_key' => 'test-gemini-key']);
        Sleep::fake();

        Http::fake([
            'generativelanguage.googleapis.com/v1beta/interactions' => Http::sequence()
                ->push([
                    'error' => ['message' => 'The model is overloaded due to high demand. Please try again later.'],
                ], 503)
                ->push($this->callQualityInteractionPayload(82, 12, 75)),
        ]);

        $result = (new WhisperTranscriptionService)->analyzeCallQuality('Hello');

        $this->assertSame([
            'courtesy_score' => 82,
            'rudeness_score' => 12,
            'emotional_tone_score' => 75,
            'inappropriate_expressions' => null,
        ], $result);
        Http::assertSentCount(2);
        Sleep::assertSequence([
            Sleep::for(2)->seconds(),
        ]);
    }

    public function test_analyze_call_quality_retries_when_error_body_asks_to_try_again_later(): void
    {
        config(['whisper.gemini_api_key' => 'test-gemini-key']);
        Sleep::fake();

        Http::fake([
            'generativelanguage.googleapis.com/v1beta/interactions' => Http::sequence()
                ->push([
                    'error' => ['message' => 'Please try again later.'],
                ], 500)
                ->push($this->callQualityInteractionPayload(70, 10, 80)),
        ]);

        $result = (new WhisperTranscriptionService)->analyzeCallQuality('Hello');

        $this->assertSame(70, $result['courtesy_score']);
        Http::assertSentCount(2);
        Sleep::assertSleptTimes(1);
    }

    public function test_analyze_call_quality_does_not_retry_non_overload_client_errors(): void
    {
        config(['whisper.gemini_api_key' => 'test-gemini-key']);
        Sleep::fake();

        Http::fake([
            'generativelanguage.googleapis.com/v1beta/interactions' => Http::response([
                'error' => ['message' => 'Invalid argument'],
            ], 400),
        ]);

        try {
            (new WhisperTranscriptionService)->analyzeCallQuality('Hello');
            $this->fail('Expected TranscriptionFailedException to be thrown.');
        } catch (TranscriptionFailedException $exception) {
            $this->assertSame('Invalid argument', $exception->getMessage());
        }

        Http::assertSentCount(1);
        Sleep::assertSleptTimes(0);
    }

    public function test_analyze_call_quality_throws_when_response_json_is_invalid(): void
    {
        config(['whisper.gemini_api_key' => 'test-gemini-key']);

        Http::fake([
            'generativelanguage.googleapis.com/v1beta/interactions' => Http::response([
                'steps' => [
                    [
                        'content' => [
                            [
                                'type' => 'text',
                                'text' => 'not json',
                            ],
                        ],
                    ],
                ],
            ]),
        ]);

        $this->expectException(TranscriptionFailedException::class);
        $this->expectExceptionMessage('invalid JSON');

        (new WhisperTranscriptionService)->analyzeCallQuality('Hello');
    }

    public function test_analyze_call_quality_throws_when_score_is_out_of_range(): void
    {
        config(['whisper.gemini_api_key' => 'test-gemini-key']);

        Http::fake([
            'generativelanguage.googleapis.com/v1beta/interactions' => Http::response([
                'steps' => [
                    [
                        'content' => [
                            [
                                'type' => 'text',
                                'text' => '{"courtesy_score":101,"rudeness_score":0,"emotional_tone_score":50}',
                            ],
                        ],
                    ],
                ],
            ]),
        ]);

        $this->expectException(TranscriptionFailedException::class);
        $this->expectExceptionMessage('out-of-range courtesy_score');

        (new WhisperTranscriptionService)->analyzeCallQuality('Hello');
    }

    public function test_analyze_call_quality_parses_inappropriate_expressions_by_speaker(): void
    {
        config(['whisper.gemini_api_key' => 'test-gemini-key']);

        Http::fake([
            'generativelanguage.googleapis.com/v1beta/interactions' => Http::response([
                'steps' => [
                    [
                        'content' => [
                            [
                                'type' => 'text',
                                'text' => '{"courtesy_score":70,"rudeness_score":20,"emotional_tone_score":65,"inappropriate_expressions":[{"speaker":"operator","text":"axmaq"},{"speaker":"musteri","text":"lanət"}]}',
                            ],
                        ],
                    ],
                ],
            ]),
        ]);

        $result = (new WhisperTranscriptionService)->analyzeCallQuality('Hello');

        $this->assertSame([
            'courtesy_score' => 70,
            'rudeness_score' => 20,
            'emotional_tone_score' => 65,
            'inappropriate_expressions' => [
                ['speaker' => 'operator', 'text' => 'axmaq'],
                ['speaker' => 'musteri', 'text' => 'lanət'],
            ],
        ], $result);
    }

    public function test_analyze_segment_quality_parses_gemini_json_response(): void
    {
        config([
            'whisper.gemini_api_key' => 'test-gemini-key',
            'whisper.gemini_model' => 'gemini-3.6-flash',
        ]);

        Http::fake([
            'generativelanguage.googleapis.com/v1beta/interactions' => Http::response([
                'model' => 'gemini-3.6-flash',
                'steps' => [
                    [
                        'type' => 'model_output',
                        'content' => [
                            [
                                'type' => 'text',
                                'text' => '[{"segment_index":0,"courtesy_score":90,"rudeness_score":5,"emotional_tone_score":88},{"segment_index":1,"courtesy_score":70,"rudeness_score":20,"emotional_tone_score":60}]',
                            ],
                        ],
                    ],
                ],
            ]),
        ]);

        $segments = [
            ['speaker' => 'operator', 'start' => 0.0, 'end' => 2.0, 'text' => 'Buyurun.'],
            ['speaker' => 'musteri', 'start' => 2.5, 'end' => 4.0, 'text' => 'Salam.'],
        ];

        $result = (new WhisperTranscriptionService)->analyzeSegmentQuality($segments);

        $this->assertSame([
            [
                'courtesy_score' => 90,
                'rudeness_score' => 5,
                'emotional_tone_score' => 88,
            ],
            [
                'courtesy_score' => 70,
                'rudeness_score' => 20,
                'emotional_tone_score' => 60,
            ],
        ], $result);
    }

    public function test_analyze_segment_quality_throws_when_segment_count_mismatches(): void
    {
        config(['whisper.gemini_api_key' => 'test-gemini-key']);

        Http::fake([
            'generativelanguage.googleapis.com/v1beta/interactions' => Http::response([
                'steps' => [
                    [
                        'content' => [
                            [
                                'type' => 'text',
                                'text' => '[{"segment_index":0,"courtesy_score":90,"rudeness_score":5,"emotional_tone_score":88}]',
                            ],
                        ],
                    ],
                ],
            ]),
        ]);

        $this->expectException(TranscriptionFailedException::class);
        $this->expectExceptionMessage('returned 1 scores, expected 2');

        (new WhisperTranscriptionService)->analyzeSegmentQuality([
            ['speaker' => 'operator', 'start' => 0.0, 'end' => 2.0, 'text' => 'Buyurun.'],
            ['speaker' => 'musteri', 'start' => 2.5, 'end' => 4.0, 'text' => 'Salam.'],
        ]);
    }

    public function test_analyze_segment_quality_retries_transient_overload_then_succeeds(): void
    {
        config(['whisper.gemini_api_key' => 'test-gemini-key']);
        Sleep::fake();

        Http::fake([
            'generativelanguage.googleapis.com/v1beta/interactions' => Http::sequence()
                ->push([
                    'error' => ['message' => 'The service is under high demand.'],
                ], 429)
                ->push($this->segmentQualityInteractionPayload()),
        ]);

        $result = (new WhisperTranscriptionService)->analyzeSegmentQuality([
            ['speaker' => 'operator', 'start' => 0.0, 'end' => 2.0, 'text' => 'Buyurun.'],
        ]);

        $this->assertSame([
            [
                'courtesy_score' => 90,
                'rudeness_score' => 5,
                'emotional_tone_score' => 88,
            ],
        ], $result);
        Http::assertSentCount(2);
        Sleep::assertSequence([
            Sleep::for(2)->seconds(),
        ]);
    }

    /**
     * @return array{model: string, steps: list<array{content: list<array{type: string, text: string}>}>}
     */
    private function callQualityInteractionPayload(int $courtesy, int $rudeness, int $tone): array
    {
        return [
            'model' => 'gemini-3.6-flash',
            'steps' => [
                [
                    'content' => [
                        [
                            'type' => 'text',
                            'text' => '{"courtesy_score":'.$courtesy.',"rudeness_score":'.$rudeness.',"emotional_tone_score":'.$tone.'}',
                        ],
                    ],
                ],
            ],
        ];
    }

    /**
     * @return array{steps: list<array{content: list<array{type: string, text: string}>}>}
     */
    private function segmentQualityInteractionPayload(): array
    {
        return [
            'steps' => [
                [
                    'content' => [
                        [
                            'type' => 'text',
                            'text' => '[{"segment_index":0,"courtesy_score":90,"rudeness_score":5,"emotional_tone_score":88}]',
                        ],
                    ],
                ],
            ],
        ];
    }
}
