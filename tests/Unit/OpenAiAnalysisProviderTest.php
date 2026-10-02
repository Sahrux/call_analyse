<?php

namespace Tests\Unit;

use App\Exceptions\TranscriptionFailedException;
use App\Services\Analysis\OpenAiAnalysisProvider;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class OpenAiAnalysisProviderTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        config([
            'services.openai.key' => 'test-openai-key',
            'services.openai.analysis_model' => 'gpt-6-luna',
        ]);
    }

    public function test_parses_fenced_json_clamps_scores_and_keeps_emotional_tone(): void
    {
        Http::preventStrayRequests();
        Http::fake([
            'https://api.openai.com/v1/responses' => Http::response([
                'output' => [
                    ['type' => 'reasoning', 'content' => []],
                    [
                        'type' => 'message',
                        'content' => [
                            ['type' => 'reasoning_text', 'text' => 'ignore me'],
                            [
                                'type' => 'output_text',
                                'text' => "```json\n{\"courtesy_score\":150,\"rudeness_score\":-3,\"emotional_tone_score\":65,\"inappropriate_expressions\":[{\"speaker\":\"musteri\",\"text\":\"zibil\"}]}\n```",
                            ],
                        ],
                    ],
                ],
            ]),
        ]);

        $scores = app(OpenAiAnalysisProvider::class)->analyze('ignored', [
            ['speaker' => 'operator', 'start' => 0.0, 'end' => 1.0, 'text' => 'Salam'],
        ]);

        $this->assertSame(100, $scores['courtesy_score']);
        $this->assertSame(0, $scores['rudeness_score']);
        $this->assertSame(65, $scores['emotional_tone_score']);
        $this->assertSame([
            ['speaker' => 'musteri', 'text' => 'zibil'],
        ], $scores['inappropriate_expressions']);
    }

    public function test_invalid_json_throws(): void
    {
        Http::preventStrayRequests();
        Http::fake([
            'https://api.openai.com/v1/responses' => Http::response([
                'output' => [
                    [
                        'type' => 'message',
                        'content' => [
                            ['type' => 'output_text', 'text' => 'this is not json'],
                        ],
                    ],
                ],
            ]),
        ]);

        $this->expectException(TranscriptionFailedException::class);
        $this->expectExceptionMessage('OpenAI analysis returned invalid JSON.');

        app(OpenAiAnalysisProvider::class)->analyze('Salam', [
            ['speaker' => 'operator', 'start' => 0.0, 'end' => 1.0, 'text' => 'Salam'],
        ]);
    }

    public function test_sends_time_ordered_transcript_lines_and_the_configured_model(): void
    {
        Http::preventStrayRequests();
        Http::fake([
            'https://api.openai.com/v1/responses' => Http::response([
                'output' => [
                    [
                        'type' => 'message',
                        'content' => [
                            ['type' => 'output_text', 'text' => '{"courtesy_score":90,"rudeness_score":0,"emotional_tone_score":10,"inappropriate_expressions":[]}'],
                        ],
                    ],
                ],
            ]),
        ]);

        app(OpenAiAnalysisProvider::class)->analyze('blob that must not be sent', [
            ['speaker' => 'musteri', 'start' => 2.0, 'end' => 3.0, 'text' => 'Yaxşıyam'],
            ['speaker' => 'customer', 'start' => 4.0, 'end' => 5.0, 'text' => 'Sağ ol'],
            ['speaker' => 'operator', 'start' => 0.2, 'end' => 1.0, 'text' => 'Salam'],
        ]);

        Http::assertSent(function (Request $request): bool {
            $payload = $request->data();
            $userText = $payload['input'][1]['content'][0]['text'] ?? '';
            $developerText = $payload['input'][0]['content'][0]['text'] ?? '';

            return $request->url() === 'https://api.openai.com/v1/responses'
                && $request->hasHeader('Authorization', 'Bearer test-openai-key')
                && $payload['model'] === 'gpt-6-luna'
                && $payload['store'] === false
                && $payload['stream'] === false
                && ($payload['reasoning']['effort'] ?? null) === 'medium'
                && ($payload['text']['format']['type'] ?? null) === 'text'
                && $payload['input'][0]['role'] === 'developer'
                && $payload['input'][1]['role'] === 'user'
                && str_contains($developerText, 'courtesy_score')
                && $userText === "operator: Salam\nmusteri: Yaxşıyam\nmusteri: Sağ ol"
                && ! str_contains($userText, 'blob that must not be sent');
        });
    }

    public function test_missing_api_key_throws_before_a_request(): void
    {
        config(['services.openai.key' => '']);
        Http::preventStrayRequests();
        Http::fake();

        $this->expectException(TranscriptionFailedException::class);
        $this->expectExceptionMessage('OPENAI_API_KEY təyin edilməyib');

        app(OpenAiAnalysisProvider::class)->analyze('Salam', [
            ['speaker' => 'operator', 'start' => 0.0, 'end' => 1.0, 'text' => 'Salam'],
        ]);
    }

    public function test_non_success_response_throws_the_response_message(): void
    {
        Http::preventStrayRequests();
        Http::fake([
            'https://api.openai.com/v1/responses' => Http::response([
                'error' => ['message' => 'insufficient quota'],
            ], 429),
        ]);

        $this->expectException(TranscriptionFailedException::class);
        $this->expectExceptionMessage('insufficient quota');

        app(OpenAiAnalysisProvider::class)->analyze('Salam', [
            ['speaker' => 'operator', 'start' => 0.0, 'end' => 1.0, 'text' => 'Salam'],
        ]);
    }
}
