<?php

namespace Tests\Feature;

use App\Jobs\TranscribeCallJob;
use App\Models\Call;
use App\Models\Operator;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Sleep;
use Tests\TestCase;

class SeedCallsFromFolderCommandTest extends TestCase
{
    use RefreshDatabase;

    public function test_seed_command_creates_calls_and_dispatches_transcription_jobs(): void
    {
        Storage::fake('public');
        Queue::fake([TranscribeCallJob::class]);
        Sleep::fake();

        Operator::query()->create([
            'name' => 'Aysel Məmmədova',
            'employee_code' => 'OP-001',
            'email' => 'aysel@example.com',
            'phone' => '+994501234567',
            'is_active' => true,
        ]);

        $fixturePath = base_path('tests/Fixtures/audio');

        $exitCode = Artisan::call('calls:seed-from-folder', [
            '--path' => $fixturePath,
            '--delay' => 0,
        ]);

        $this->assertSame(0, $exitCode);
        $this->assertSame(2, Call::query()->count());

        $calls = Call::query()->orderBy('id')->get();

        foreach ($calls as $call) {
            $this->assertSame(1, $call->operator_id);
            $this->assertSame('az', $call->language);
            $this->assertSame(Call::STATUS_PENDING, $call->status);
            $this->assertNotNull($call->customer_name);
            $this->assertMatchesRegularExpression('/^\+994 \d{2} \d{3} \d{2} \d{2}$/', (string) $call->customer_phone);
            $this->assertNotNull($call->called_at);
            $this->assertTrue($call->called_at->greaterThanOrEqualTo(now()->subDays(30)->subMinute()));
            $this->assertTrue($call->called_at->lessThanOrEqualTo(now()->addMinute()));
            $this->assertNotNull($call->audio_path);
            $this->assertTrue(Storage::disk('public')->exists($call->audio_path));
            $this->assertTrue(str_starts_with((string) $call->audio_path, 'calls/audio/'));
            $this->assertContains($call->audio_original_name, ['call-one.wav', 'call-two.mp3']);
        }

        Queue::assertPushed(TranscribeCallJob::class, 2);
        Queue::assertPushed(TranscribeCallJob::class, function (TranscribeCallJob $job) use ($calls): bool {
            return $calls->contains(fn (Call $call): bool => $job->call->is($call));
        });

        $output = Artisan::output();
        $this->assertStringContainsString('Seeded: 2', $output);
        $this->assertStringContainsString('empty.wav', $output);
        $this->assertStringContainsString('notes.txt', $output);
    }

    public function test_dry_run_does_not_create_calls_or_dispatch_jobs(): void
    {
        Storage::fake('public');
        Queue::fake([TranscribeCallJob::class]);

        Operator::query()->create([
            'name' => 'Elvin Quliyev',
            'employee_code' => 'OP-002',
            'email' => 'elvin@example.com',
            'phone' => '+994501111111',
            'is_active' => true,
        ]);

        $exitCode = Artisan::call('calls:seed-from-folder', [
            '--path' => base_path('tests/Fixtures/audio'),
            '--dry-run' => true,
        ]);

        $this->assertSame(0, $exitCode);
        $this->assertSame(0, Call::query()->count());
        Queue::assertNothingPushed();
        $this->assertSame([], Storage::disk('public')->allFiles());

        $output = Artisan::output();
        $this->assertStringContainsString('[dry-run]', $output);
        $this->assertStringContainsString('call-one.wav', $output);
        $this->assertStringContainsString('call-two.mp3', $output);
    }

    public function test_seed_command_fails_when_operator_one_is_missing(): void
    {
        Storage::fake('public');
        Queue::fake([TranscribeCallJob::class]);

        $exitCode = Artisan::call('calls:seed-from-folder', [
            '--path' => base_path('tests/Fixtures/audio'),
            '--delay' => 0,
        ]);

        $this->assertSame(1, $exitCode);
        $this->assertSame(0, Call::query()->count());
        Queue::assertNothingPushed();
    }
}
