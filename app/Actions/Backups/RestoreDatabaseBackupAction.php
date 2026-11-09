<?php

namespace App\Actions\Backups;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Process;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use RuntimeException;
use ZipArchive;

/**
 * Restores a backup archive produced by spatie/laravel-backup into the
 * configured PostgreSQL connection. The current schema is dropped first.
 */
class RestoreDatabaseBackupAction
{
    /** @return list<string> backup paths on the disk, newest first */
    public function available(string $disk): array
    {
        $files = collect(Storage::disk($disk)->allFiles())
            ->filter(fn (string $path) => str_ends_with($path, '.zip'))
            ->sortByDesc(fn (string $path) => Storage::disk($disk)->lastModified($path))
            ->values()
            ->all();

        return $files;
    }

    /**
     * @param  string|null  $filesTarget  where uploaded files go back (default storage/app/public);
     *                                    files are added or replaced, never deleted.
     */
    public function execute(string $disk, string $path, ?string $connection = null, bool $withFiles = true, ?string $filesTarget = null): void
    {
        $connection ??= config('database.default');
        $config = config("database.connections.{$connection}");

        if (($config['driver'] ?? null) !== 'pgsql') {
            throw new RuntimeException('Restore is only supported for PostgreSQL.');
        }

        $workDir = storage_path('app/restore-'.Str::random(8));
        mkdir($workDir, 0700, true);

        try {
            $zipPath = $workDir.'/backup.zip';
            $stream = Storage::disk($disk)->readStream($path) ?? throw new RuntimeException("Backup [{$path}] not found.");
            file_put_contents($zipPath, $stream);

            $zip = new ZipArchive;
            if ($zip->open($zipPath) !== true) {
                throw new RuntimeException("Backup [{$path}] is not a valid archive.");
            }

            $password = config('backup.backup.password');
            if ($password) {
                $zip->setPassword($password);
            }

            $zip->extractTo($workDir);
            $zip->close();

            $dump = collect(glob($workDir.'/db-dumps/*') ?: [])->first()
                ?? throw new RuntimeException("Backup [{$path}] contains no database dump.");

            if (str_ends_with($dump, '.gz')) {
                Process::run(['gunzip', '-f', $dump])->throw();
                $dump = substr($dump, 0, -3);
            }

            $dump = $this->makeCompatible($dump);

            DB::connection($connection)->unprepared('DROP SCHEMA public CASCADE; CREATE SCHEMA public;');
            DB::connection($connection)->disconnect();

            Process::env(['PGPASSWORD' => (string) $config['password']])
                ->timeout(3600)
                ->run([
                    'psql', '--set', 'ON_ERROR_STOP=1', '--quiet',
                    '--host', (string) $config['host'],
                    '--port', (string) $config['port'],
                    '--username', (string) $config['username'],
                    '--dbname', (string) $config['database'],
                    '--file', $dump,
                ])
                ->throw();

            if ($withFiles) {
                $this->restoreFiles($workDir, $filesTarget ?? storage_path('app/public'));
            }
        } finally {
            Process::run(['rm', '-rf', $workDir]);
        }
    }

    /**
     * Uploaded files (logo, used tyre photos…) are in "public/" inside the
     * archive; older archives used absolute paths ending in storage/app/public.
     */
    private function restoreFiles(string $workDir, string $target): void
    {
        $source = is_dir($workDir.'/public') ? $workDir.'/public'
            : (collect(glob($workDir.'/{,*/,*/*/,*/*/*/}storage/app/public', GLOB_BRACE | GLOB_ONLYDIR) ?: [])->first());

        if (! $source) {
            return; // database-only backup
        }

        if (! is_dir($target)) {
            mkdir($target, 0775, true);
        }

        Process::run(['cp', '-a', rtrim($source, '/').'/.', rtrim($target, '/').'/'])->throw();
    }

    /**
     * A dump made by a newer pg_dump may set parameters an older server does
     * not know (e.g. pg_dump 17 → "transaction_timeout"). Those session
     * settings are not data: drop them so a backup restores on any server.
     */
    private function makeCompatible(string $dump): string
    {
        $out = $dump.'.compat.sql';
        $in = fopen($dump, 'rb');
        $target = fopen($out, 'wb');

        while (($line = fgets($in)) !== false) {
            if (! preg_match('/^SET transaction_timeout\b/', $line)) {
                fwrite($target, $line);
            }
        }

        fclose($in);
        fclose($target);

        return $out;
    }
}
