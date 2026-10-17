<?php

namespace App\Console\Commands;

use App\Support\Backup\BackupNaming;
use App\Support\Monitoring\Heartbeat;
use App\Support\SchoolCalendar;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Process;
use Illuminate\Support\Str;
use Symfony\Component\Process\Process as SymfonyProcess;

/**
 * The one job this command does: take a database dump + a storage archive,
 * encrypt both, upload them off-server, and prune old copies. See
 * DISASTER_RECOVERY.md for the full picture (schedule, destination,
 * retention, and how to actually restore from what this produces).
 *
 * Deliberately does NOT restore anything -- that is a separate, deliberate,
 * documented procedure (scripts/backup/restore.sh), never something this
 * scheduled command could accidentally trigger.
 */
class BackupRun extends Command
{
    protected $signature = 'backup:run {--keep-local : do not delete the working directory afterwards (for inspection/testing)}';

    protected $description = 'Dump the database and archive file storage, encrypt both, and upload them off-server.';

    private BackupNaming $naming;

    public function handle(): int
    {
        $this->naming = new BackupNaming(config('backup.retention'));
        $passphrase = config('backup.passphrase');

        if (blank($passphrase)) {
            $this->error('BACKUP_ENCRYPTION_PASSPHRASE is not set. Refusing to produce an unencrypted backup.');

            return self::FAILURE;
        }

        $today = SchoolCalendar::today(); // the Addis Ababa day, so "today's backup" matches what the school calls today
        $stem = $this->naming->stemFor($today);
        // Forward slashes even on Windows: some tools invoked below (tar, in particular) misread a
        // "C:\..." backslash path as a remote host spec rather than a local drive path.
        $work = $this->normalize(storage_path('app/backup-work/'.Str::random(8)));
        File::ensureDirectoryExists($work);

        try {
            $this->info("Backing up as of {$stem} (business day)...");

            $dumpPath = $this->dumpDatabase($work, $stem);
            $storagePath = $this->archiveStorage($work, $stem);

            $encryptedDump = $this->encrypt($dumpPath, $passphrase);
            $encryptedStorage = $storagePath ? $this->encrypt($storagePath, $passphrase) : null;

            $manifest = $this->writeManifest($work, $stem, array_filter([$encryptedDump, $encryptedStorage]));

            $folders = $this->naming->foldersFor($today);
            $this->info('Uploading to: '.implode(', ', $folders));

            foreach ($folders as $folder) {
                foreach (array_filter([$encryptedDump, $encryptedStorage, $manifest]) as $file) {
                    $this->upload($file, $folder);
                }
            }

            foreach (['daily', 'weekly', 'monthly'] as $folder) {
                $this->prune($folder, $today);
            }

            $this->info('Backup complete.');
            Heartbeat::ping(config('backup.heartbeat.run_url'), success: true);

            return self::SUCCESS;
        } catch (\Throwable $e) {
            $this->error('Backup failed: '.$e->getMessage());

            report($e);
            Heartbeat::ping(config('backup.heartbeat.run_url'), success: false);

            return self::FAILURE;
        } finally {
            if (! $this->option('keep-local')) {
                File::deleteDirectory($work);
            }
        }
    }

    /** @return string path to the plain (unencrypted) dump file, driver-aware (pgsql or mysql). */
    private function dumpDatabase(string $work, string $stem): string
    {
        $driver = config('database.default');
        $conn = config("database.connections.{$driver}");

        if ($driver === 'pgsql') {
            $path = "{$work}/{$stem}.dump";
            $result = Process::env(['PGPASSWORD' => (string) $conn['password']])
                ->timeout(600)
                ->run([
                    'pg_dump',
                    '--format=custom', '--no-owner', '--no-privileges',
                    '--host', $conn['host'], '--port', (string) $conn['port'],
                    '--username', $conn['username'], '--dbname', $conn['database'],
                    '--file', $path,
                ]);
            $this->assertOk($result, 'pg_dump');

            return $path;
        }

        if (in_array($driver, ['mysql', 'mariadb'], true)) {
            $path = "{$work}/{$stem}.sql.gz";
            // mysqldump's stdout is piped straight through gzip so the plain SQL is never written to disk unencrypted.
            $result = Process::env(['MYSQL_PWD' => (string) $conn['password']])
                ->timeout(600)
                ->run(sprintf(
                    'mysqldump --single-transaction --quick --routines --triggers -h %s -P %s -u %s %s | gzip -9 > %s',
                    escapeshellarg($conn['host']), escapeshellarg((string) $conn['port']),
                    escapeshellarg($conn['username']), escapeshellarg($conn['database']), escapeshellarg($path)
                ));
            $this->assertOk($result, 'mysqldump');

            return $path;
        }

        throw new \RuntimeException("No backup strategy for database driver [{$driver}].");
    }

    /** Tars every configured storage path into one archive, or returns null if there is nothing to back up yet. */
    private function archiveStorage(string $work, string $stem): ?string
    {
        $paths = array_values(array_filter(
            array_map(fn ($p) => $this->normalize($p), config('backup.storage_paths')),
            fn ($p) => File::isDirectory($p) && File::exists($p)
        ));

        if ($paths === [] || collect($paths)->every(fn ($p) => File::allFiles($p) === [])) {
            $this->comment('No files in storage to back up yet.');

            return null;
        }

        $base = $this->normalize(dirname($paths[0], 2)); // storage_path('app') -- the common parent of public/ and private/
        $path = "{$work}/{$stem}.storage.tar.gz";
        $result = Process::path($base)->timeout(600)->run([
            'tar', '-czf', $path, ...array_map(fn ($p) => Str::after($p, "{$base}/"), $paths),
        ]);
        $this->assertOk($result, 'tar');

        return $path;
    }

    private function encrypt(string $plainPath, string $passphrase): string
    {
        $encryptedPath = $plainPath.'.gpg';
        $result = Process::input($passphrase)->timeout(300)->run([
            'gpg', '--batch', '--yes', '--pinentry-mode', 'loopback', '--symmetric', '--cipher-algo', 'AES256',
            '--passphrase-fd', '0', '--output', $encryptedPath, $plainPath,
        ]);
        $this->assertOk($result, 'gpg');
        File::delete($plainPath); // the plaintext dump never leaves this machine, encrypted or not

        return $encryptedPath;
    }

    /** A small JSON sidecar: what this backup contains and a checksum for each file, so integrity is checkable after transfer without decrypting first. */
    private function writeManifest(string $work, string $stem, array $files): string
    {
        $manifest = [
            'taken_at' => now()->toIso8601String(),
            'business_day' => $stem,
            'database_driver' => config('database.default'),
            'app_version_migrations' => (int) \DB::table('migrations')->count(),
            'files' => collect($files)->mapWithKeys(fn ($f) => [basename($f) => [
                'bytes' => File::size($f),
                'sha256' => hash_file('sha256', $f),
            ]])->all(),
        ];

        $path = "{$work}/{$stem}.manifest.json";
        File::put($path, json_encode($manifest, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));

        return $path;
    }

    private function upload(string $localPath, string $folder): void
    {
        $remote = config('backup.rclone_remote');
        $prefix = config('backup.rclone_bucket_path');
        $destination = "{$remote}:{$prefix}/{$folder}/";

        $result = Process::timeout(600)->run(['rclone', 'copyto', $localPath, $destination.basename($localPath)]);
        $this->assertOk($result, "rclone copyto ({$folder})");
    }

    /** Deletes remote objects in $folder whose date (parsed from the filename) has aged out of that folder's retention. */
    private function prune(string $folder, \Carbon\CarbonInterface $today): void
    {
        $remote = config('backup.rclone_remote');
        $prefix = config('backup.rclone_bucket_path');
        $target = "{$remote}:{$prefix}/{$folder}/";

        $listing = Process::timeout(120)->run(['rclone', 'lsjson', $target]);
        if (! $listing->successful()) {
            $this->warn("Could not list {$target} to prune it (this does not fail the backup; the bucket's own lifecycle rule is the backstop): ".$listing->errorOutput());

            return;
        }

        $entries = json_decode($listing->output(), true) ?? [];
        foreach ($entries as $entry) {
            $date = $this->naming->dateFromFilename($entry['Name']);
            if ($date && $this->naming->isExpired($date, $folder, $today)) {
                Process::timeout(60)->run(['rclone', 'deletefile', $target.$entry['Name']]);
                $this->comment("Pruned {$folder}/{$entry['Name']} (past the {$folder} retention window).");
            }
        }
    }

    private function assertOk(SymfonyProcess|\Illuminate\Contracts\Process\ProcessResult $result, string $what): void
    {
        if (! $result->successful()) {
            throw new \RuntimeException("{$what} failed: ".$result->errorOutput());
        }
    }

    /** Forward slashes throughout, whatever OS this runs on -- some of the external tools we shell out to (tar, in particular) misparse a Windows "C:\..." path. */
    private function normalize(string $path): string
    {
        return str_replace('\\', '/', $path);
    }
}
