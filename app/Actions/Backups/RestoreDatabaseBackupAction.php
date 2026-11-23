<?php

namespace App\Actions\Backups;

use App\Exceptions\PartialRestoreException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Process;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use RuntimeException;
use Throwable;
use ZipArchive;

/**
 * Restores a backup archive produced by spatie/laravel-backup (PostgreSQL only).
 *
 * - intoNewDatabase() (recommended): the archive goes into a NEW database, and its files into a
 *   NEW private folder outside the web tree; the current database and the live files are never
 *   touched. If anything fails, both are removed (each removal is attempted on its own).
 * - replacingCurrent(): replaces the current database. Before anything changes, the archive is
 *   checked and the extracted files are checked against the live folder (no obstacle). The old
 *   schema is then dropped and the dump loaded in ONE transaction: on error nothing changed.
 *   Finally the files are put in place one by one (copy, SHA-256 check, atomic rename); the
 *   source is never consumed. Database and files are NOT one atomic operation: if putting the
 *   files in place fails, the database was restored and a PartialRestoreException says so; the
 *   archive, unchanged, finishes the job with filesOnly() (repeatable).
 * - filesOnly(): puts the files of an archive in place, without touching the database.
 *
 * No copy is ever written inside the public web tree (storage/app/public is served as /storage
 * when the storage link exists), except each file's own temporary copy, next to its final place,
 * for the time of one copy.
 *
 * Server settings come from the live connection, so a connection configured with DB_URL
 * (Railway) is restored on the right server.
 */
class RestoreDatabaseBackupAction
{
    /**
     * Restored copies of the uploaded files (for checking a restore): in the private storage,
     * outside the public web tree. Not on the Railway volume: the durable copy is the archive.
     */
    public static function restoredFilesRoot(): string
    {
        return storage_path('app/private/restored');
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
     * @param  string|null  $filesTarget  new private folder for the uploaded files (default {restoredFilesRoot}/{database});
     *                                    it must not exist and must not be in the public web tree
     * @param  string|null  $documentsTarget  new private folder for the private documents (expense receipts)
     *                                        (default {restoredFilesRoot}/{database}-documents); same rules
     */
    public function intoNewDatabase(string $disk, string $path, string $database, ?string $connection = null, bool $withFiles = true, ?string $filesTarget = null, ?string $documentsTarget = null): void
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
        $documentsTarget ??= self::restoredFilesRoot().'/'.$database.'-documents';
        if ($withFiles) {
            foreach ([$filesTarget, $documentsTarget] as $folder) {
                $this->assertPrivate($folder);
                if (file_exists($folder)) {
                    throw new RuntimeException("Files target [{$folder}] already exists.");
                }
            }
        }

        $this->withExtractedArchive($disk, $path, function (string $workDir, string $dump) use ($config, $connection, $database, $withFiles, $filesTarget, $documentsTarget) {
            $createdFiles = false;
            $createdDocuments = false;
            $createdDatabase = false;

            try {
                $source = $withFiles ? $this->filesSource($workDir) : null;
                if ($source) {
                    $createdFiles = true;
                    $this->copyVerified($source, $filesTarget);
                }
                $documents = $withFiles ? $this->documentsSource($workDir) : null;
                if ($documents) {
                    $createdDocuments = true;
                    $this->copyVerified($documents, $documentsTarget);
                }

                DB::connection($connection)->statement('CREATE DATABASE "'.$database.'"');
                $createdDatabase = true;
                $this->psql([...$config, 'database' => $database], [$dump]);
            } catch (Throwable $e) {
                // Only what was created just above is removed; each removal is tried on its own,
                // and what could not be removed is reported without hiding the original error.
                $leftovers = [];
                if ($createdDatabase) {
                    try {
                        $this->dropDatabase($connection, $database);
                    } catch (Throwable $cleanup) {
                        report($cleanup);
                        $leftovers[] = "database [{$database}]";
                    }
                }
                if ($createdFiles && file_exists($filesTarget) && ! File::deleteDirectory($filesTarget)) {
                    $leftovers[] = "folder [{$filesTarget}]";
                }
                if ($createdDocuments && file_exists($documentsTarget) && ! File::deleteDirectory($documentsTarget)) {
                    $leftovers[] = "folder [{$documentsTarget}]";
                }
                if ($leftovers !== []) {
                    Log::error('Restore failed and could not remove: '.implode(', ', $leftovers).'. Remove them by hand.', ['error' => $e->getMessage()]);
                }

                throw $e;
            }
        });
    }

    /**
     * @param  string|null  $filesTarget  where uploaded files go back (default storage/app/public);
     *                                    files are added or replaced, never deleted.
     * @param  string|null  $documentsTarget  where private documents go back (default storage/app/documents)
     *
     * @throws PartialRestoreException when the database was replaced but the files could not all be put in place
     */
    public function replacingCurrent(string $disk, string $path, ?string $connection = null, bool $withFiles = true, ?string $filesTarget = null, ?string $documentsTarget = null): void
    {
        $config = $this->connectionConfig($connection);
        $target = $filesTarget ?? storage_path('app/public');
        $documentsTarget ??= storage_path('app/documents');
        if ($withFiles) {
            $this->assertPrivate($documentsTarget);
        }

        $this->withExtractedArchive($disk, $path, function (string $workDir, string $dump) use ($config, $connection, $withFiles, $target, $documentsTarget, $path, $disk) {
            // 1. Before any change: the files can all be put in place (no obstacle).
            $source = $withFiles ? $this->filesSource($workDir) : null;
            $files = $source ? $this->manifest($source) : [];
            $this->assertPlaceable(array_keys($files), $target);
            $documents = $withFiles ? $this->documentsSource($workDir) : null;
            $documentFiles = $documents ? $this->manifest($documents) : [];
            $this->assertPlaceable(array_keys($documentFiles), $documentsTarget);

            // 2. Database replaced in one transaction: on error, nothing changed.
            $prelude = $workDir.'/replace-schema.sql';
            file_put_contents($prelude, "DROP SCHEMA public CASCADE;\nCREATE SCHEMA public;\n");
            DB::connection($connection)->disconnect();
            $this->psql($config, [$prelude, $dump]);

            // 3. Files put in place one by one; the extracted source is never consumed.
            //    Public files first, then private documents; the count covers both.
            $placed = 0;
            $total = count($files) + count($documentFiles);
            try {
                foreach ($files as $relative => $sha256) {
                    $this->placeFile($source.'/'.$relative, $target.'/'.$relative, $sha256);
                    $placed++;
                }
                foreach ($documentFiles as $relative => $sha256) {
                    $this->placeFile($documents.'/'.$relative, $documentsTarget.'/'.$relative, $sha256);
                    $placed++;
                }
            } catch (Throwable $e) {
                throw new PartialRestoreException($disk, $path, $target, $placed, $total, $e);
            }
        });
    }

    /**
     * Puts the uploaded files of an archive in place (added or replaced, never deleted) without
     * touching the database. Repeatable: finishes a restore whose files were not all put in place.
     * The archive must open (right password, intact entries) and contain the uploaded files; its
     * database dump is not needed.
     */
    public function filesOnly(string $disk, string $path, ?string $filesTarget = null, ?string $documentsTarget = null): void
    {
        $target = $filesTarget ?? storage_path('app/public');
        $documentsTarget ??= storage_path('app/documents');
        $this->assertPrivate($documentsTarget);

        $this->withExtractedArchive($disk, $path, withDump: false, callback: function (string $workDir) use ($target, $documentsTarget, $path) {
            $source = $this->filesSource($workDir);
            $documents = $this->documentsSource($workDir);
            if (! $source && ! $documents) {
                throw new RuntimeException("Backup [{$path}] contains no uploaded files.");
            }
            $files = $source ? $this->manifest($source) : [];
            $documentFiles = $documents ? $this->manifest($documents) : [];
            $this->assertPlaceable(array_keys($files), $target);
            $this->assertPlaceable(array_keys($documentFiles), $documentsTarget);

            foreach ($files as $relative => $sha256) {
                $this->placeFile($source.'/'.$relative, $target.'/'.$relative, $sha256);
            }
            foreach ($documentFiles as $relative => $sha256) {
                $this->placeFile($documents.'/'.$relative, $documentsTarget.'/'.$relative, $sha256);
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
     * Downloads and extracts the archive (readable, right password, intact entries) and, when
     * the database is restored, checks the dump is complete BEFORE the callback may change
     * anything. Restoring files only does not depend on the dump, so a damaged dump does not
     * prevent saving the files. The work directory is always removed.
     *
     * @param  callable(string $workDir, ?string $dump): void  $callback
     */
    private function withExtractedArchive(string $disk, string $path, callable $callback, bool $withDump = true): void
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

            if (! $withDump) {
                $callback($workDir, null);

                return;
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

    /**
     * Private documents (expense receipts) are in "documents/" inside the archive (storage/app/documents,
     * archived relative to storage/app). Null when the archive has none (older archives, or no receipt yet).
     */
    private function documentsSource(string $workDir): ?string
    {
        return is_dir($workDir.'/documents') ? $workDir.'/documents'
            : (collect(glob($workDir.'/{,*/,*/*/,*/*/*/}storage/app/documents', GLOB_BRACE | GLOB_ONLYDIR) ?: [])->first());
    }

    /** Copies a folder and checks every file arrived identical (SHA-256). */
    protected function copyVerified(string $source, string $target): void
    {
        File::ensureDirectoryExists($target, 0700);
        $this->copyTree($source, $target);

        if ($this->manifest($target) !== $this->manifest($source)) {
            throw new RuntimeException("The files copied into [{$target}] do not match the archive (SHA-256).");
        }
    }

    protected function copyTree(string $source, string $target): void
    {
        Process::run(['cp', '-a', rtrim($source, '/').'/.', rtrim($target, '/').'/'])->throw();
    }

    /**
     * Puts one file in place: copied to a temporary file next to its destination, checked
     * (SHA-256), then renamed over it in one step. The source is left as it is.
     */
    protected function placeFile(string $from, string $to, string $sha256): void
    {
        File::ensureDirectoryExists(dirname($to), 0775);
        $temporary = dirname($to).'/.restore-'.Str::random(12).'.tmp';

        try {
            $this->copyFile($from, $temporary);
            if (hash_file('sha256', $temporary) !== $sha256) {
                throw new RuntimeException('The copy of ['.basename($to).'] does not match the archive (SHA-256).');
            }
            if (! @rename($temporary, $to)) {
                throw new RuntimeException('Could not put ['.basename($to).'] in place.');
            }
        } finally {
            @unlink($temporary);
        }
    }

    protected function copyFile(string $from, string $to): void
    {
        if (! @copy($from, $to)) {
            throw new RuntimeException('Could not copy ['.basename($from).'].');
        }
    }

    protected function dropDatabase(?string $connection, string $database): void
    {
        DB::connection($connection)->statement('DROP DATABASE IF EXISTS "'.$database.'" WITH (FORCE)');
    }

    /**
     * Refuses, before anything changes, a file that cannot be put in place (a file where the
     * archive has a folder, or the reverse).
     *
     * @param  list<string>  $relatives
     */
    private function assertPlaceable(array $relatives, string $target): void
    {
        foreach ($relatives as $relative) {
            $path = rtrim($target, '/');
            foreach (explode('/', dirname($relative)) as $segment) {
                if ($segment === '.') {
                    continue;
                }
                $path .= '/'.$segment;
                if (file_exists($path) && ! is_dir($path)) {
                    throw new RuntimeException("Cannot restore files: [{$path}] is a file where the archive has a folder. Nothing was changed.");
                }
            }
            if (is_dir($target.'/'.$relative)) {
                throw new RuntimeException("Cannot restore files: [{$target}/{$relative}] is a folder where the archive has a file. Nothing was changed.");
            }
        }
    }

    /** @return array<string, string> relative path => SHA-256, sorted */
    private function manifest(string $directory): array
    {
        $files = [];
        foreach (File::allFiles($directory, true) as $file) {
            $files[$file->getRelativePathname()] = hash_file('sha256', $file->getPathname());
        }
        ksort($files);

        return $files;
    }

    /**
     * Restored copies must stay private: the target may not be, contain or sit inside the
     * public web tree (storage/app/public, served as /storage, or public/).
     */
    private function assertPrivate(string $target): void
    {
        $target = $this->normalizePath($target);

        foreach ([storage_path('app/public'), public_path()] as $public) {
            $public = $this->normalizePath($public);
            if ($target === $public || str_starts_with($target, $public.'/') || str_starts_with($public.'/', $target.'/')) {
                throw new RuntimeException("Files target [{$target}] is in, or contains, the public web tree: choose a private folder (default: ".self::restoredFilesRoot().'/<database>).');
            }
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
