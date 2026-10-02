<?php

namespace App\Console\Commands;

use App\Jobs\TranscribeCallJob;
use App\Models\Call;
use App\Models\Operator;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;
use Illuminate\Http\File;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Sleep;
use Throwable;

#[Signature('calls:seed-from-folder
    {--path=dataset : Folder of audio files (relative to project root, or absolute)}
    {--delay=5 : Seconds to wait between each job dispatch}
    {--dry-run : Preview creations without copying files, writing DB rows, or dispatching jobs}')]
#[Description('Bulk-import audio files as Call records and queue transcription/analysis (demo/test seeding)')]
class SeedCallsFromFolder extends Command
{
    private const ALLOWED_EXTENSIONS = ['mp3', 'wav', 'm4a', 'ogg', 'webm'];

    /**
     * @var list<string>
     */
    private const AZERBAIJANI_FIRST_NAMES = [
        'Kamran', 'Leyla', 'Orxan', 'Sevinc', 'Tural', 'Aysel', 'Elvin', 'Nigar',
        'Rəşad', 'Günel', 'Farid', 'Könül', 'Vüsal', 'Aygün', 'Ramin', 'Sevil',
        'Elnur', 'Lalə', 'Murad', 'Zümrüd', 'Cavid', 'Narmin', 'Samir', 'Gülşən',
        'Tunar', 'Mehriban', 'Anar', 'Fidan', 'İlkin', 'Sabina',
    ];

    /**
     * @var list<string>
     */
    private const AZERBAIJANI_LAST_NAMES = [
        'İsmayılov', 'Babayeva', 'Cəfərov', 'Rəhimova', 'Nəbiyev', 'Məmmədova',
        'Quliyev', 'Həsənova', 'Əliyev', 'Hüseynova', 'Abdullayev', 'Mustafayeva',
        'Karimov', 'Əhmədova', 'Rzayev', 'Süleymanova', 'Vəliyev', 'Qasımova',
        'Bayramov', 'Şahbazova', 'Məlikov', 'Yusifova', 'Hacıyev', 'Tağıyeva',
    ];

    /**
     * Azerbaijani mobile prefixes (without leading 0).
     *
     * @var list<string>
     */
    private const AZ_MOBILE_PREFIXES = ['50', '51', '55', '70', '77', '99', '10'];

    public function handle(): int
    {
        $folder = $this->resolveFolderPath((string) $this->option('path'));
        $delay = max(0, (int) $this->option('delay'));
        $dryRun = (bool) $this->option('dry-run');

        if (! is_dir($folder)) {
            $this->error("Folder not found: {$folder}");

            return self::FAILURE;
        }

        if (! $dryRun && ! Operator::query()->whereKey(1)->exists()) {
            $this->error('Operator with id=1 does not exist. Run `php artisan db:seed` first, or create an operator.');

            return self::FAILURE;
        }

        $entries = $this->listFolderEntries($folder);

        if ($entries === []) {
            $this->warn("No files found in {$folder}");

            return self::SUCCESS;
        }

        $audioFiles = [];
        $skipped = [];

        foreach ($entries as $entry) {
            $extension = strtolower(pathinfo($entry, PATHINFO_EXTENSION));

            if (! in_array($extension, self::ALLOWED_EXTENSIONS, true)) {
                $skipped[] = [
                    'file' => basename($entry),
                    'reason' => "unsupported extension (.{$extension})",
                ];

                continue;
            }

            $audioFiles[] = $entry;
        }

        $total = count($audioFiles);

        if ($total === 0) {
            $this->warn('No audio files with supported extensions (mp3, wav, m4a, ogg, webm) found.');
            $this->printSkippedSummary($skipped);

            return self::SUCCESS;
        }

        if ($dryRun) {
            $this->info("Dry run — {$total} audio file(s) would be seeded from {$folder}");
        } else {
            $this->info("Seeding {$total} audio file(s) from {$folder} (delay={$delay}s between dispatches)");
        }

        $seeded = 0;

        foreach ($audioFiles as $index => $absolutePath) {
            $originalName = basename($absolutePath);
            $position = $index + 1;

            try {
                if (! is_readable($absolutePath)) {
                    $skipped[] = [
                        'file' => $originalName,
                        'reason' => 'file is not readable',
                    ];
                    $this->warn("Skipped {$position}/{$total}: {$originalName} (not readable)");

                    continue;
                }

                $size = filesize($absolutePath);

                if ($size === false || $size === 0) {
                    $skipped[] = [
                        'file' => $originalName,
                        'reason' => 'empty or unreadable file (0 bytes)',
                    ];
                    $this->warn("Skipped {$position}/{$total}: {$originalName} (0 bytes)");

                    continue;
                }

                $customerName = $this->randomAzerbaijaniName();
                $customerPhone = $this->randomAzerbaijaniPhone();
                $calledAt = now()->subSeconds(random_int(0, 30 * 24 * 60 * 60));

                if ($dryRun) {
                    $this->line(sprintf(
                        '[dry-run] %d/%d: %s | %s | %s | %s',
                        $position,
                        $total,
                        $originalName,
                        $customerName,
                        $customerPhone,
                        $calledAt->toDateTimeString()
                    ));
                    $seeded++;

                    continue;
                }

                $path = Storage::disk('public')->putFile('calls/audio', new File($absolutePath));

                if ($path === false) {
                    $skipped[] = [
                        'file' => $originalName,
                        'reason' => 'failed to copy into public storage',
                    ];
                    $this->warn("Skipped {$position}/{$total}: {$originalName} (storage copy failed)");

                    continue;
                }

                $call = Call::query()->create([
                    'operator_id' => 1,
                    'customer_name' => $customerName,
                    'customer_phone' => $customerPhone,
                    'language' => 'az',
                    'called_at' => $calledAt,
                    'audio_path' => $path,
                    'audio_original_name' => $originalName,
                    'status' => Call::STATUS_PENDING,
                ]);

                TranscribeCallJob::dispatch($call);
                $seeded++;

                $this->line(sprintf(
                    'Seeded %d/%d: %s, %s',
                    $seeded,
                    $total,
                    $customerName,
                    $originalName
                ));

                if ($delay > 0 && $position < $total) {
                    Sleep::for($delay)->seconds();
                }
            } catch (Throwable $exception) {
                $skipped[] = [
                    'file' => $originalName,
                    'reason' => $exception->getMessage(),
                ];
                $this->warn("Skipped {$position}/{$total}: {$originalName} ({$exception->getMessage()})");
            }
        }

        $this->newLine();
        $this->info($dryRun
            ? "Dry-run complete. Would seed: {$seeded}. Skipped: ".count($skipped).'.'
            : "Done. Seeded: {$seeded}. Skipped: ".count($skipped).'.');

        $this->printSkippedSummary($skipped);

        if (! $dryRun && $seeded > 0) {
            $this->newLine();
            $this->comment('Ensure a queue worker is running (restart if it was already up): php artisan queue:work --timeout=120');
        }

        return self::SUCCESS;
    }

    private function resolveFolderPath(string $path): string
    {
        if ($path !== '' && (str_starts_with($path, DIRECTORY_SEPARATOR) || preg_match('/^[A-Za-z]:[\\\\\\/]/', $path) === 1)) {
            return $path;
        }

        return base_path($path !== '' ? $path : 'dataset');
    }

    /**
     * @return list<string>
     */
    private function listFolderEntries(string $folder): array
    {
        $entries = scandir($folder);

        if ($entries === false) {
            return [];
        }

        $paths = [];

        foreach ($entries as $entry) {
            if ($entry === '.' || $entry === '..') {
                continue;
            }

            $absolutePath = $folder.DIRECTORY_SEPARATOR.$entry;

            if (is_file($absolutePath)) {
                $paths[] = $absolutePath;
            }
        }

        natcasesort($paths);

        return array_values($paths);
    }

    private function randomAzerbaijaniName(): string
    {
        $first = self::AZERBAIJANI_FIRST_NAMES[array_rand(self::AZERBAIJANI_FIRST_NAMES)];
        $last = self::AZERBAIJANI_LAST_NAMES[array_rand(self::AZERBAIJANI_LAST_NAMES)];

        return "{$first} {$last}";
    }

    private function randomAzerbaijaniPhone(): string
    {
        $prefix = self::AZ_MOBILE_PREFIXES[array_rand(self::AZ_MOBILE_PREFIXES)];
        $part1 = str_pad((string) random_int(0, 999), 3, '0', STR_PAD_LEFT);
        $part2 = str_pad((string) random_int(0, 99), 2, '0', STR_PAD_LEFT);
        $part3 = str_pad((string) random_int(0, 99), 2, '0', STR_PAD_LEFT);

        return "+994 {$prefix} {$part1} {$part2} {$part3}";
    }

    /**
     * @param  list<array{file: string, reason: string}>  $skipped
     */
    private function printSkippedSummary(array $skipped): void
    {
        if ($skipped === []) {
            return;
        }

        $this->newLine();
        $this->warn('Skipped files:');

        foreach ($skipped as $item) {
            $this->line("  - {$item['file']}: {$item['reason']}");
        }
    }
}
