<?php

namespace App\Jobs;

use App\Models\Call;
use App\Models\CallAnalysis;
use App\Models\Setting;
use App\Services\AiProviderResolver;
use Illuminate\Bus\UniqueLock;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Throwable;

class TranscribeCallJob implements ShouldBeUnique, ShouldQueue
{
    use Queueable;

    public int $tries = 2;

    public int $timeout = 120;

    /**
     * @var list<int>
     */
    public array $backoff = [60, 180];

    public function __construct(public Call $call)
    {
        $provider = (string) Setting::getValue(AiProviderResolver::TRANSCRIPTION_SETTING, 'gemini');

        $this->timeout = $provider === 'elevenlabs'
            ? 360
            : (int) config('whisper.timeout', 120);
    }

    public function uniqueId(): string
    {
        return (string) $this->call->id;
    }

    /**
     * Seconds the unique lock is held. Laravel's UniqueLock reads this method
     * (or a #[UniqueFor] attribute), not the public $uniqueFor property — without
     * this, the lock TTL becomes 0 and the database driver defaults to 24 hours.
     */
    public function uniqueFor(): int
    {
        return max(60, $this->timeout);
    }

    public static function releaseUniqueLock(Call $call): void
    {
        (new UniqueLock(Cache::store()))->release(new static($call));
    }

    public function handle(AiProviderResolver $providers): void
    {
        $call = $this->call->fresh();

        if (! $call || ! $call->audio_path) {
            return;
        }

        $call->update([
            'status' => Call::STATUS_PROCESSING,
            'error_message' => null,
        ]);

        $provider = $providers->transcription();
        $absolutePath = Storage::disk('public')->path($call->audio_path);
        $result = $provider->transcribe($absolutePath, $call->language);

        DB::transaction(function () use ($call, $provider, $result): void {
            CallAnalysis::query()->updateOrCreate(
                ['call_id' => $call->id],
                [
                    'transcript' => $result['text'],
                    'transcript_segments' => $result['segments'],
                    'raw_result' => [
                        'model' => $result['model'],
                        'whisper_language' => $result['whisper_language'],
                    ],
                    'analyzed_at' => now(),
                ]
            );

            $call->update([
                'transcription_provider' => $provider->name(),
                'transcription_model' => $provider->model(),
            ]);
        });

        dispatch(new AnalyzeCallJob($call));

        $call->update([
            'status' => Call::STATUS_COMPLETED,
            'error_message' => null,
            'duration_seconds' => $result['duration_seconds'] ?? $call->duration_seconds,
        ]);
    }

    public function failed(?Throwable $exception): void
    {
        static::releaseUniqueLock($this->call);

        $call = $this->call->fresh();

        if (! $call) {
            return;
        }

        $call->update([
            'status' => Call::STATUS_FAILED,
            'error_message' => $exception?->getMessage() ?: 'Transcription failed',
        ]);
    }
}
