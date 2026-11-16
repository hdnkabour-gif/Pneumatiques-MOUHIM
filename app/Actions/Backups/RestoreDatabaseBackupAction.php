<?php

namespace App\Actions\Backups;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Process;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use RuntimeException;
use Throwable;
use ZipArchive;

/**
 * Restores a backup archive produced by spatie/laravel-backup (PostgreSQL only).
 *
 * Two ways, both safe when something goes wrong:
 * - intoNewDatabase(): the recommended way. The archive goes into a NEW database on the same
 *   server and the current database is never touched; it can be checked before switching to it.
 * - replacingCurrent(): replaces the current database. The archive is fully checked first, then
 *   the old schema is dropped and the dump is loaded in ONE transaction: if anything fails, the
 *   database stays exactly as it was.
 *
 * Server settings come from the live connection, so a connection configured with DB_URL
 * (Railway) is restored on the right server.
 */
class RestoreDatabaseBackupAction
{
    /** @return list<string> backup paths on the disk, newest first */
    public function available(string $disk): array
    {
        return collect(Storage::disk($disk)->allFiles())
            ->filter(fn (string $path) => str_ends_with($path, '.zip'))
            ->sortByDesc(fn (string $path) => Storage::disk($disk)->lastModified($path))
            ->values()
            ->all();
    }

    /**
     * @param  string|null  $filesTarget  where uploaded files go (default storage/app/restored/{database}/public,
     *                                    never the live storage/app/public)
     */
    public function intoNewDatabase(string $disk, string $path, string $database, ?string $connection = null, bool $withFiles = true, ?string $filesTarget = null): void
    {
        $config = $this->connectionConfig($connection);

        if (! preg_match('/^[a-z][a-z0-9_]{0,62}$/', $database)) {
            throw new RuntimeException("Invalid database name [{$database}]: lowercase letters, digits and underscores only.");
        }
        if ($database === $config['database']) {
            throw new RuntimeException("[{$database}] is the current database: restore into a new database.");
        }
        if (DB::connection($connection)->table('pg_database')->where('datname', $database)->exists()) {
            throw new RuntimeException("Database [{$database}] already exists: choose a new name.");
        }

        $filesTarget ??= storage_path("app/restored/{$database}/public");
        if ($withFiles && file_exists($filesTarget)) {
            throw new RuntimeException("Files target [{$filesTarget}] already exists.");
        }

        $this->withExtractedArchive($disk, $path, function (string $workDir, string $dump) use ($config, $connection, $database, $withFiles, $filesTarget) {
            DB::connection($connection)->statement('CREATE DATABASE "'.$database.'"');

            try {
                $this->psql([...$config, 'database' => $database], [$dump]);
            } catch (Throwable $e) {
                // Only the database created just above is removed.
                DB::connection($connection)->statement('DROP DATABASE IF EXISTS "'.$database.'" WITH (FORCE)');

                throw $e;
            }

            if ($withFiles) {
                $this->restoreFiles($workDir, $filesTarget);
            }
        });
    }

    /**
     * @param  string|null  $filesTarget  where uploaded files go back (default storage/app/public);
     *                                    files are added or replaced, never deleted.
     */
    public function replacingCurrent(string $disk, string $path, ?string $connection = null, bool $withFiles = true, ?string $filesTarget = null): void
    {
        $config = $this->connectionConfig($connection);

        $this->withExtractedArchive($disk, $path, function (string $workDir, string $dump) use ($config, $connection, $withFiles, $filesTarget) {
            $prelude = $workDir.'/replace-schema.sql';
            file_put_contents($prelude, "DROP SCHEMA public CASCADE;\nCREATE SCHEMA public;\n");

            DB::connection($connection)->disconnect();
            $this->psql($config, [$prelude, $dump]);

            if ($withFiles) {
                $this->restoreFiles($workDir, $filesTarget ?? storage_path('app/public'));
            }
        });
    }

    /** Settings of the live connection: DB_URL is already parsed into host, port, database… */
    private function connectionConfig(?string $connection): array
    {
        $config = DB::connection($connection)->getConfig();

        if (($config['driver'] ?? null) !== 'pgsql') {
            throw new RuntimeException('Restore is only supported for PostgreSQL.');
        }

        return $config;
    }

    /**
     * Downloads and extracts the archive, and checks the dump is complete BEFORE the
     * callback may change anything. The work directory is always removed.
     *
     * @param  callable(string $workDir, string $dump): void  $callback
     */
    private function withExtractedArchive(string $disk, string $path, callable $callback): void
    {
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

            $extracted = $zip->extractTo($workDir);
            $zip->close();
            if (! $extracted) {
                throw new RuntimeException("Backup [{$path}] could not be extracted (damaged archive or wrong BACKUP_ARCHIVE_PASSWORD).");
            }

            $dump = collect(glob($workDir.'/db-dumps/*') ?: [])->first()
                ?? throw new RuntimeException("Backup [{$path}] contains no database dump.");

            if (str_ends_with($dump, '.gz')) {
                Process::run(['gunzip', '-f', $dump])->throw();
                $dump = substr($dump, 0, -3);
            }

            $callback($workDir, $this->checkedDump($dump, $path));
        } finally {
            Process::run(['rm', '-rf', $workDir]);
        }
    }

    /**
     * Returns a copy of the dump ready for psql, after checking it is a complete pg_dump
     * output (a truncated file lacks the "dump complete" footer).
     *
     * A dump made by a newer pg_dump may set parameters an older server does not know
     * (e.g. pg_dump 17 → "transaction_timeout"). Those session settings are not data:
     * they are dropped so a backup restores on any server.
     */
    private function checkedDump(string $dump, string $path): string
    {
        $out = $dump.'.compat.sql';
        $in = fopen($dump, 'rb');
        $target = fopen($out, 'wb');
        $header = false;
        $complete = false;
        $lineNumber = 0;

        while (($line = fgets($in)) !== false) {
            $lineNumber++;
            if ($lineNumber <= 5 && str_starts_with($line, '-- PostgreSQL database dump')) {
                $header = true;
            }
            if (str_starts_with($line, '-- PostgreSQL database dump complete')) {
                $complete = true;
            }
            if (! preg_match('/^SET transaction_timeout\b/', $line)) {
                fwrite($target, $line);
            }
        }

        fclose($in);
        fclose($target);

        if (! $header || ! $complete) {
            throw new RuntimeException("Backup [{$path}] does not contain a complete PostgreSQL dump: nothing was changed.");
        }

        return $out;
    }

    /**
     * Runs the SQL files in a single transaction: on the first error everything is rolled back.
     *
     * @param  list<string>  $files
     */
    private function psql(array $config, array $files): void
    {
        $arguments = ['psql', '--set', 'ON_ERROR_STOP=1', '--single-transaction', '--quiet',
            '--host', (string) $config['host'],
            '--port', (string) $config['port'],
            '--username', (string) $config['username'],
            '--dbname', (string) $config['database'],
        ];
        foreach ($files as $file) {
            array_push($arguments, '--file', $file);
        }

        Process::env(array_filter([
            'PGPASSWORD' => (string) ($config['password'] ?? ''),
            'PGSSLMODE' => $config['sslmode'] ?? null,
        ]))->timeout(3600)->run($arguments)->throw();
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
}
