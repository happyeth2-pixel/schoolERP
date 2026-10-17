<?php

namespace App\Console\Commands;

use App\Support\Monitoring\Heartbeat;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Process;
use Illuminate\Support\Str;

/**
 * A daily smoke test that runs right after backup:run: fetches the backup that
 * was JUST uploaded, checks every file's checksum against its manifest,
 * decrypts it, and confirms the dump/archive are structurally readable --
 * all WITHOUT touching any real database. This is what catches a broken
 * backup within the day it broke, instead of during an actual emergency.
 *
 * It is not a substitute for the periodic full restore drill in
 * DISASTER_RECOVERY.md (restoring into a real, empty database and checking
 * the application actually works) -- only for that drill's cheaper, daily
 * cousin: "is today's file even a valid, decryptable, complete backup".
 */
class BackupVerify extends Command
{
    protected $signature = 'backup:verify {--folder=daily : which retention folder to check the latest backup from}';

    protected $description = 'Download the most recent backup, verify its checksums, and confirm it decrypts and parses -- without restoring anything.';

    public function handle(): int
    {
        $passphrase = config('backup.passphrase');
        if (blank($passphrase)) {
            $this->error('BACKUP_ENCRYPTION_PASSPHRASE is not set.');
            Heartbeat::ping(config('backup.heartbeat.verify_url'), success: false);

            return self::FAILURE;
        }

        $remote = config('backup.rclone_remote');
        $prefix = config('backup.rclone_bucket_path');
        $folder = $this->option('folder');
        $source = "{$remote}:{$prefix}/{$folder}/";

        $work = storage_path('app/backup-work/verify-'.Str::random(8));
        File::ensureDirectoryExists($work);

        try {
            $listing = Process::timeout(120)->run(['rclone', 'lsjson', $source]);
            if (! $listing->successful()) {
                $this->error('Could not list the backup destination: '.$listing->errorOutput());
                Heartbeat::ping(config('backup.heartbeat.verify_url'), success: false);

                return self::FAILURE;
            }

            $entries = collect(json_decode($listing->output(), true) ?? []);
            $manifestEntry = $entries->first(fn ($e) => str_ends_with($e['Name'], '.manifest.json'));
            if (! $manifestEntry) {
                $this->error("No manifest found in {$source} -- either nothing has been backed up yet, or backup:run failed before writing one.");
                Heartbeat::ping(config('backup.heartbeat.verify_url'), success: false);

                return self::FAILURE;
            }

            $this->download($source.$manifestEntry['Name'], "{$work}/manifest.json");
            $manifest = json_decode(File::get("{$work}/manifest.json"), true);
            $this->info("Checking backup for business day {$manifest['business_day']}...");

            $ok = true;
            foreach ($manifest['files'] as $name => $expected) {
                $localPath = "{$work}/{$name}";
                $this->download($source.$name, $localPath);

                $actualHash = hash_file('sha256', $localPath);
                if ($actualHash !== $expected['sha256']) {
                    $this->error("Checksum mismatch for {$name}: expected {$expected['sha256']}, got {$actualHash}.");
                    $ok = false;

                    continue;
                }
                $this->line("  ✓ {$name} checksum matches ({$expected['bytes']} bytes).");

                $decrypted = Str::beforeLast($localPath, '.gpg');
                $decrypt = Process::input($passphrase)->timeout(120)->run([
                    'gpg', '--batch', '--yes', '--pinentry-mode', 'loopback', '--decrypt', '--passphrase-fd', '0', '--output', $decrypted, $localPath,
                ]);
                if (! $decrypt->successful()) {
                    $this->error("  ✗ {$name} did not decrypt: ".$decrypt->errorOutput());
                    $ok = false;

                    continue;
                }

                $ok = $this->checkStructure($decrypted, $name) && $ok;
            }

            if ($ok) {
                $this->info('Backup verified: every file present, checksums match, and each one is decryptable and structurally readable.');
            }

            Heartbeat::ping(config('backup.heartbeat.verify_url'), success: $ok);

            return $ok ? self::SUCCESS : self::FAILURE;
        } finally {
            File::deleteDirectory($work);
        }
    }

    private function download(string $remotePath, string $localPath): void
    {
        $result = Process::timeout(300)->run(['rclone', 'copyto', $remotePath, $localPath]);
        if (! $result->successful()) {
            throw new \RuntimeException("Could not download {$remotePath}: ".$result->errorOutput());
        }
    }

    /** A cheap structural check per file type -- lists the dump's contents, or lists the tar archive -- never restores/extracts anything. */
    private function checkStructure(string $decryptedPath, string $originalName): bool
    {
        if (str_contains($originalName, '.dump')) {
            $result = Process::timeout(60)->run(['pg_restore', '--list', $decryptedPath]);
        } elseif (str_ends_with($originalName, '.sql.gz.gpg')) {
            $result = Process::timeout(60)->run(['gzip', '-t', $decryptedPath]);
        } elseif (str_contains($originalName, '.tar.gz')) {
            $result = Process::timeout(60)->run(['tar', '-tzf', $decryptedPath]);
        } else {
            return true; // the manifest.json itself; nothing further to structurally check
        }

        if (! $result->successful()) {
            $this->error("  ✗ {$originalName} decrypted but is not a readable backup: ".$result->errorOutput());

            return false;
        }
        $this->line("  ✓ {$originalName} is a structurally valid backup file.");

        return true;
    }
}
