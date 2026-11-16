<?php

namespace App\Actions\Backups;

use App\Exceptions\PartialRestoreException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Process;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use RuntimeException;
use Throwable;
use ZipArchive;

/**
 * Restores a backup archive produced by spatie/laravel-backup (PostgreSQL only).
 *
 * - intoNewDatabase() (recommended): the archive goes into a NEW database and a NEW folder of
 *   files; the current database and the live files are never touched. If anything fails, both
 *   the new database and the new folder are removed.
 * - replacingCurrent(): replaces the current database. Before anything is changed, the archive
 *   is checked and the files are copied and verified next to their destination. The old schema
 *   is then dropped and the dump loaded in ONE transaction: if that fails, the database and the
 *   live files are unchanged. Only the last step, moving the verified files into place, can fail
 *   after the database was replaced: a PartialRestoreException then says so and keeps the copy.
 *
 * Server settings come from the live connection, so a connection configured with DB_URL
 * (Railway) is restored on the right server.
 */
class RestoreDatabaseBackupAction
{
    /**
     * Restored copies of the uploaded files: inside storage/app/public, which is the persistent
     * volume on Railway, but apart from the live files (the app never reads them, and they are
     * excluded from the backups).
     */
    public static function restoredFilesRoot(): string
    {
        return storage_path('app/public/_restored');
    }

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
     * @param  string|null  $filesTarget  new folder for the uploaded files (default {restoredFilesRoot}/{database});
     *                                    it must not exist and must not overlap the live files
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
        $canCreate = DB::connection($connection)->selectOne('SELECT rolsuper OR rolcreatedb AS allowed FROM pg_roles WHERE rolname = current_user');
        if (! ($canCreate?->allowed ?? false)) {
            throw new RuntimeException('The database user may not create databases (CREATEDB): restore into a separate PostgreSQL service instead.');
        }

        $filesTarget ??= self::restoredFilesRoot().'/'.$database;
        if ($withFiles) {
            $this->assertApartFromLiveFiles($filesTarget);
            if (file_exists($filesTarget)) {
                throw new RuntimeException("Files target [{$filesTarget}] already exists.");
            }
        }

        $this->withExtractedArchive($disk, $path, function (string $workDir, string $dump) use ($config, $connection, $database, $withFiles, $filesTarget) {
            $createdFiles = false;
            $createdDatabase = false;

            try {
                $source = $withFiles ? $this->filesSource($workDir) : null;
                if ($source) {
                    $createdFiles = true;
                    $this->copyVerified($source, $filesTarget);
                }

                DB::connection($connection)->statement('CREATE DATABASE "'.$database.'"');
                $createdDatabase = true;
                $this->psql([...$config, 'database' => $database], [$dump]);
            } catch (Throwable $e) {
                // Only what was created just above is removed.
                if ($createdDatabase) {
                    DB::connection($connection)->statement('DROP DATABASE IF EXISTS "'.$database.'" WITH (FORCE)');
                }
                if ($createdFiles) {
                    File::deleteDirectory($filesTarget);
                }

                throw $e;
            }
        });
    }

    /**
     * @param  string|null  $filesTarget  where uploaded files go back (default storage/app/public);
     *                                    files are added or replaced, never deleted.
     *
     * @throws PartialRestoreException when the database was replaced but the files could not all be moved into place
     */
    public function replacingCurrent(string $disk, string $path, ?string $connection = null, bool $withFiles = true, ?string $filesTarget = null): void
    {
        $config = $this->connectionConfig($connection);
        $target = $filesTarget ?? storage_path('app/public');

        $this->withExtractedArchive($disk, $path, function (string $workDir, string $dump) use ($config, $connection, $withFiles, $target) {
            // 1. Files copied and verified next to their destination (same disk), before any change.
            $staging = null;
            $source = $withFiles ? $this->filesSource($workDir) : null;
            if ($source) {
                $staging = rtrim($target, '/').'/.restore-staging-'.Str::random(8);
                try {
                    $this->copyVerified($source, $staging);
                    $this->assertMovable($staging, $target);
                } catch (Throwable $e) {
                    File::deleteDirectory($staging);

                    throw $e;
                }
            }

            // 2. Database replaced in one transaction: on error, nothing changed.
            try {
                $prelude = $workDir.'/replace-schema.sql';
                file_put_contents($prelude, "DROP SCHEMA public CASCADE;\nCREATE SCHEMA public;\n");
                DB::connection($connection)->disconnect();
                $this->psql($config, [$prelude, $dump]);
            } catch (Throwable $e) {
                if ($staging) {
                    File::deleteDirectory($staging);
                }

                throw $e;
            }

            // 3. Verified files moved into place (renames on the same disk).
            if ($staging) {
                try {
                    $this->moveFiles($staging, $target);
                } catch (Throwable $e) {
                    throw new PartialRestoreException($staging, $target, $e);
                }
                File::deleteDirectory($staging);
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
     * Uploaded files (logo, used tyre photos…) are in "public/" inside the archive; older
     * archives used absolute paths ending in storage/app/public. Null for a database-only backup.
     */
    private function filesSource(string $workDir): ?string
    {
        return is_dir($workDir.'/public') ? $workDir.'/public'
            : (collect(glob($workDir.'/{,*/,*/*/,*/*/*/}storage/app/public', GLOB_BRACE | GLOB_ONLYDIR) ?: [])->first());
    }

    /** Copies a folder and checks that every file arrived with its exact size. */
    protected function copyVerified(string $source, string $target): void
    {
        File::ensureDirectoryExists($target, 0775);
        Process::run(['cp', '-a', rtrim($source, '/').'/.', rtrim($target, '/').'/'])->throw();

        if ($this->manifest($target) !== $this->manifest($source)) {
            throw new RuntimeException("The files copied into [{$target}] do not match the archive.");
        }
    }

    /** Refuses, before anything changes, a file that cannot be moved into place (e.g. a folder in the way). */
    private function assertMovable(string $staging, string $target): void
    {
        foreach (array_keys($this->manifest($staging)) as $relative) {
            $path = rtrim($target, '/');
            foreach (explode('/', dirname($relative)) as $segment) {
                if ($segment === '.') {
                    continue;
                }
                $path .= '/'.$segment;
                if (file_exists($path) && ! is_dir($path)) {
                    throw new RuntimeException("Cannot restore files: [{$path}] is a file where the archive has a folder.");
                }
            }
            if (is_dir($target.'/'.$relative)) {
                throw new RuntimeException("Cannot restore files: [{$target}/{$relative}] is a folder where the archive has a file.");
            }
        }
    }

    /** Moves every verified file into place; files already there are replaced, none is deleted. */
    protected function moveFiles(string $staging, string $target): void
    {
        foreach (array_keys($this->manifest($staging)) as $relative) {
            $destination = $target.'/'.$relative;
            File::ensureDirectoryExists(dirname($destination), 0775);
            if (! @rename($staging.'/'.$relative, $destination)) {
                throw new RuntimeException("Could not move [{$relative}] into [{$target}].");
            }
        }
    }

    /** @return array<string, int> relative path => size, sorted */
    private function manifest(string $directory): array
    {
        $files = [];
        foreach (File::allFiles($directory, true) as $file) {
            $files[$file->getRelativePathname()] = $file->getSize();
        }
        ksort($files);

        return $files;
    }

    /**
     * A new restore must never write over the live files: the target may not be, contain, or
     * sit inside storage/app/public, except inside the dedicated _restored folder.
     */
    private function assertApartFromLiveFiles(string $target): void
    {
        $live = $this->normalizePath(storage_path('app/public'));
        $restored = $this->normalizePath(self::restoredFilesRoot());
        $target = $this->normalizePath($target);

        $overlaps = $target === $live
            || str_starts_with($live.'/', $target.'/')
            || (str_starts_with($target, $live.'/') && ! str_starts_with($target, $restored.'/'));

        if ($overlaps) {
            throw new RuntimeException("Files target [{$target}] overlaps the live uploaded files: choose a new folder (default: {$restored}/<database>).");
        }
    }

    /** Absolute path with "." / ".." resolved and symbolic links of the existing part followed. */
    private function normalizePath(string $path): string
    {
        $path = str_starts_with($path, '/') ? $path : base_path($path);
        $segments = [];
        foreach (explode('/', $path) as $segment) {
            if ($segment === '' || $segment === '.') {
                continue;
            }
            $segment === '..' ? array_pop($segments) : $segments[] = $segment;
        }

        $existing = '/';
        $rest = $segments;
        while ($rest !== [] && file_exists($existing.(($existing === '/') ? '' : '/').$rest[0])) {
            $existing .= ($existing === '/' ? '' : '/').array_shift($rest);
        }
        $existing = realpath($existing) ?: $existing;

        return rtrim($existing, '/').($rest === [] ? '' : '/'.implode('/', $rest));
    }
}
