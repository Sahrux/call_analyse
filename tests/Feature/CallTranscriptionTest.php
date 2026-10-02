<?php

namespace Tests\Feature;

use App\Exceptions\TranscriptionFailedException;
use App\Jobs\AnalyzeCallJob;
use App\Jobs\TranscribeCallJob;
use App\Models\Call;
use App\Models\CallAnalysis;
use App\Models\User;
use App\Services\AiProviderResolver;
use App\Services\WhisperTranscriptionService;
use Illuminate\Bus\UniqueLock;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class CallTranscriptionTest extends TestCase
{
    use RefreshDatabase;

    public function test_storing_a_call_dispatches_transcription_job(): void
    {
        Storage::fake('public');
        Queue::fake([TranscribeCallJob::class]);

        $user = User::factory()->create();
        $audio = UploadedFile::fake()->create('call.mp3', 100, 'audio/mpeg');

        $response = $this->actingAs($user)->postJson('/api/calls', [
            'language' => 'az',
            'audio' => $audio,
        ]);

        $response->assertCreated();

        $call = Call::query()->first();
        $this->assertNotNull($call);
        $this->assertSame(Call::STATUS_PENDING, $call->status);

        Queue::assertPushed(TranscribeCallJob::class, function (TranscribeCallJob $job) use ($call): bool {
            return $job->call->is($call);
        });
    }

    public function test_reprocess_dispatches_transcription_job(): void
    {
        Queue::fake([TranscribeCallJob::class]);

        $user = User::factory()->create();
        $call = Call::factory()->create([
            'status' => Call::STATUS_FAILED,
            'error_message' => 'previous error',
        ]);

        $response = $this->actingAs($user)->postJson("/api/calls/{$call->id}/reprocess");

        $response->assertOk();
        $this->assertSame(Call::STATUS_PENDING, $call->fresh()->status);
        $this->assertNull($call->fresh()->error_message);

        Queue::assertPushed(TranscribeCallJob::class, function (TranscribeCallJob $job) use ($call): bool {
            return $job->call->is($call);
        });
    }

    public function test_reprocess_returns_409_when_call_is_processing(): void
    {
        Queue::fake([TranscribeCallJob::class]);

        $user = User::factory()->create();
        $call = Call::factory()->create([
            'status' => Call::STATUS_PROCESSING,
        ]);

        $response = $this->actingAs($user)->postJson("/api/calls/{$call->id}/reprocess");

        $response->assertStatus(409)
            ->assertJsonPath('message', 'Zəng artıq işlənir, gözləyin.');

        $this->assertSame(Call::STATUS_PROCESSING, $call->fresh()->status);
        Queue::assertNothingPushed();
    }

    public function test_reprocess_queues_transcription_job_even_when_unique_lock_is_stuck(): void
    {
        $user = User::factory()->create();
        $call = Call::factory()->create([
            'status' => Call::STATUS_FAILED,
            'error_message' => 'previous error',
        ]);

        $stuckJob = new TranscribeCallJob($call);
        $this->assertTrue(
            (new UniqueLock(Cache::store()))->acquire($stuckJob),
            'Failed to simulate a stuck unique lock.'
        );

        Queue::fake([TranscribeCallJob::class]);

        $first = $this->actingAs($user)->postJson("/api/calls/{$call->id}/reprocess");
        $first->assertOk();

        Queue::assertPushed(TranscribeCallJob::class, 1);

        $second = $this->actingAs($user)->postJson("/api/calls/{$call->id}/reprocess");
        $second->assertOk();

        Queue::assertPushed(TranscribeCallJob::class, 2);
        $this->assertSame(Call::STATUS_PENDING, $call->fresh()->status);
    }

    public function test_reprocess_transcription_job_dispatches_analyze_call_job(): void
    {
        Storage::fake('public');
        Storage::disk('public')->put('calls/audio/sample.mp3', 'fake-audio');

        $user = User::factory()->create();
        $call = Call::factory()->create([
            'audio_path' => 'calls/audio/sample.mp3',
            'language' => 'az',
            'status' => Call::STATUS_FAILED,
            'error_message' => 'previous error',
        ]);

        Queue::fake([TranscribeCallJob::class]);

        $response = $this->actingAs($user)->postJson("/api/calls/{$call->id}/reprocess");
        $response->assertOk();

        Queue::assertPushed(TranscribeCallJob::class, function (TranscribeCallJob $job) use ($call): bool {
            return $job->call->is($call);
        });

        $this->mock(WhisperTranscriptionService::class, function ($mock): void {
            $mock->shouldReceive('transcribe')
                ->once()
                ->andReturn([
                    'text' => 'Yenidən analiz transkripti',
                    'segments' => [
                        ['start' => 0.0, 'end' => 1.0, 'text' => 'Yenidən analiz transkripti', 'speaker' => 'spk_1'],
                    ],
                    'duration_seconds' => 1,
                    'model' => 'gemini-3.5-transcribe',
                    'whisper_language' => 'az-AZ',
                ]);
        });

        Bus::fake([AnalyzeCallJob::class]);

        (new TranscribeCallJob($call->fresh()))->handle(app(AiProviderResolver::class));

        Bus::assertDispatched(AnalyzeCallJob::class, function (AnalyzeCallJob $job) use ($call): bool {
            return $job->call->is($call);
        });

        $this->assertSame(Call::STATUS_COMPLETED, $call->fresh()->status);
        $this->assertDatabaseHas('call_analyses', [
            'call_id' => $call->id,
            'transcript' => 'Yenidən analiz transkripti',
        ]);
    }

    public function test_transcription_job_persists_transcript_and_marks_call_completed(): void
    {
        Storage::fake('public');
        Storage::disk('public')->put('calls/audio/sample.mp3', 'fake-audio');

        $call = Call::factory()->create([
            'audio_path' => 'calls/audio/sample.mp3',
            'language' => 'az',
            'status' => Call::STATUS_PENDING,
        ]);

        $this->mock(WhisperTranscriptionService::class, function ($mock): void {
            $mock->shouldReceive('transcribe')
                ->once()
                ->andReturn([
                    'text' => 'Salam, necəsiniz?',
                    'segments' => [
                        ['start' => 0.0, 'end' => 1.5, 'text' => 'Salam, necəsiniz?'],
                    ],
                    'duration_seconds' => 2,
                    'model' => 'openai/whisper-large-v3',
                    'whisper_language' => 'turkish',
                ]);
        });

        Bus::fake([AnalyzeCallJob::class]);

        (new TranscribeCallJob($call))->handle(app(AiProviderResolver::class));

        Bus::assertDispatched(AnalyzeCallJob::class, function (AnalyzeCallJob $job) use ($call): bool {
            return $job->call->is($call);
        });

        $call->refresh();
        $this->assertSame(Call::STATUS_COMPLETED, $call->status);
        $this->assertSame(2, $call->duration_seconds);
        $this->assertNull($call->error_message);

        $analysis = CallAnalysis::query()->where('call_id', $call->id)->first();
        $this->assertNotNull($analysis);
        $this->assertSame('Salam, necəsiniz?', $analysis->transcript);
        $this->assertSame('Salam, necəsiniz?', $analysis->transcript_segments[0]['text']);
    }

    public function test_transcription_job_marks_call_failed_when_service_throws(): void
    {
        Storage::fake('public');
        Storage::disk('public')->put('calls/audio/sample.mp3', 'fake-audio');

        $call = Call::factory()->create([
            'audio_path' => 'calls/audio/sample.mp3',
            'status' => Call::STATUS_PENDING,
        ]);

        $this->mock(WhisperTranscriptionService::class, function ($mock): void {
            $mock->shouldReceive('transcribe')
                ->once()
                ->andThrow(new TranscriptionFailedException('model crashed'));
        });

        try {
            (new TranscribeCallJob($call))->handle(app(AiProviderResolver::class));
            $this->fail('Expected TranscriptionFailedException was not thrown.');
        } catch (TranscriptionFailedException $exception) {
            (new TranscribeCallJob($call))->failed($exception);
        }

        $call->refresh();
        $this->assertSame(Call::STATUS_FAILED, $call->status);
        $this->assertSame('model crashed', $call->error_message);
    }

    public function test_show_includes_audio_url_and_transcript(): void
    {
        Storage::fake('public');
        Storage::disk('public')->put('calls/audio/sample.mp3', 'fake-audio');

        $user = User::factory()->create();
        $call = Call::factory()->create([
            'audio_path' => 'calls/audio/sample.mp3',
            'status' => Call::STATUS_COMPLETED,
        ]);

        CallAnalysis::query()->create([
            'call_id' => $call->id,
            'transcript' => 'Salam dünya',
            'transcript_segments' => [
                ['start' => 0.0, 'end' => 1.2, 'text' => 'Salam dünya'],
            ],
        ]);

        $response = $this->actingAs($user)->getJson("/api/calls/{$call->id}");

        $response->assertOk()
            ->assertJsonPath('call.audio_url', route('api.calls.audio', $call))
            ->assertJsonPath('call.analysis.transcript', 'Salam dünya')
            ->assertJsonPath('call.analysis.transcript_segments.0.text', 'Salam dünya');
    }
}
