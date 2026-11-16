<?php

use App\Actions\Backups\RestoreDatabaseBackupAction;
use App\Exceptions\PartialRestoreException;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Database\QueryException;
use Illuminate\Process\Exceptions\ProcessFailedException;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Storage;

/*
| Phase 1 acceptance: "sauvegarde restaurée avec succès".
| Runs against dedicated scratch databases, outside the test transaction,
| so pg_dump / psql see real committed data.
*/

const RESTORED_DB = 'mouhim_restored_test';

beforeEach(function () {
    $base = config('database.connections.pgsql');
    config([
        'database.connections.pg_admin' => [...$base, 'database' => 'postgres'],
        'database.connections.backup_test' => [...$base, 'database' => 'mouhim_backup_test'],
    ]);

    // The backup command caches its configuration at boot: hand it a dedicated one.
    $backupConfig = config('backup');
    $backupConfig['backup']['source']['databases'] = ['backup_test'];
    $backupConfig['backup']['source']['files']['include'] = [];
    $backupConfig['backup']['destination']['disks'] = ['backups_local'];
    config(['backup_restore_test' => $backupConfig]);

    foreach (['mouhim_backup_test', RESTORED_DB] as $database) {
        DB::connection('pg_admin')->statement("DROP DATABASE IF EXISTS {$database} WITH (FORCE)");
    }
    DB::connection('pg_admin')->statement('CREATE DATABASE mouhim_backup_test');

    Artisan::call('migrate', ['--database' => 'backup_test', '--force' => true]);
    Storage::fake('backups_local');

    $db = DB::connection('backup_test');
    $db->table('sites')->insert([
        ['code' => 'DEP', 'name' => 'Dépôt Central', 'type' => 'depot', 'created_at' => now(), 'updated_at' => now()],
        ['code' => 'M01', 'name' => 'Magasin 01', 'type' => 'store', 'created_at' => now(), 'updated_at' => now()],
    ]);
    $db->table('activity_log')->insert(['description' => 'avant sauvegarde', 'created_at' => now(), 'updated_at' => now()]);
});

afterEach(function () {
    foreach (DB::getConnections() as $name => $connection) {
        if ($name !== 'pg_admin' && $name !== config('database.default')) {
            DB::purge($name);
        }
    }
    foreach (['mouhim_backup_test', RESTORED_DB] as $database) {
        DB::connection('pg_admin')->statement("DROP DATABASE IF EXISTS {$database} WITH (FORCE)");
    }
    File::deleteDirectory(RestoreDatabaseBackupAction::restoredFilesRoot());
});

/** Runs backup:run with the test configuration and returns the archive path. */
function backupNow(array $options = []): string
{
    expect(Artisan::call('backup:run', ['--config' => 'backup_restore_test', '--disable-notifications' => true, ...$options]))->toBe(0);

    return app(RestoreDatabaseBackupAction::class)->available('backups_local')[0];
}

/** Rewrites the dump of an archive (plain SQL) and stores it as a new archive. */
function archiveWithDump(string $from, callable $edit, string $to = 'tampered.zip'): string
{
    $disk = Storage::disk('backups_local');
    $zip = new ZipArchive;
    $zip->open($disk->path($from));
    $name = collect(range(0, $zip->numFiles - 1))->map(fn ($i) => $zip->getNameIndex($i))->first(fn ($n) => str_starts_with($n, 'db-dumps/'));
    $sql = gzdecode($zip->getFromName($name));
    $zip->close();

    $out = new ZipArchive;
    $out->open($disk->path($to), ZipArchive::CREATE | ZipArchive::OVERWRITE);
    $out->addFromString('db-dumps/postgresql-mouhim_backup_test.sql', $edit($sql));
    $out->close();

    return $to;
}

function siteNames(string $connection): array
{
    return DB::connection($connection)->table('sites')->orderBy('code')->pluck('name')->all();
}

it('backs up the database and restores it after data loss', function () {
    $path = backupNow(['--only-db' => true]);

    // Disaster: data lost after the backup.
    DB::connection('backup_test')->table('sites')->delete();
    expect(DB::connection('backup_test')->table('sites')->count())->toBe(0);

    app(RestoreDatabaseBackupAction::class)->replacingCurrent('backups_local', $path, 'backup_test');

    $db = DB::connection('backup_test');
    expect(siteNames('backup_test'))->toBe(['Dépôt Central', 'Magasin 01'])
        ->and($db->table('activity_log')->where('description', 'avant sauvegarde')->exists())->toBeTrue();

    // The append-only protection survives the restore.
    expect(fn () => $db->table('activity_log')->delete())->toThrow(QueryException::class);
});

it('schedules the daily backup, and cleans old backups only after it (not on its own)', function () {
    $events = collect(app(Schedule::class)->events())
        ->map(fn ($e) => $e->command);

    expect($events->contains(fn ($c) => str_contains($c, 'backup:run')))->toBeTrue()
        ->and($events->contains(fn ($c) => str_contains($c, 'backup:monitor')))->toBeTrue()
        ->and($events->contains(fn ($c) => str_contains($c, 'backup:clean')))->toBeFalse();
});

it('restores the uploaded files together with the database (audit)', function () {
    $dir = storage_path('app/public/backup-test-'.uniqid());
    mkdir($dir, 0775, true);
    file_put_contents($dir.'/logo.png', 'LOGO');

    $config = config('backup_restore_test');
    $config['backup']['source']['files']['include'] = [$dir];
    $config['backup']['source']['files']['relative_path'] = storage_path('app');
    config(['backup_restore_test' => $config]);

    try {
        $path = backupNow();
        unlink($dir.'/logo.png'); // disaster: the file is lost

        app(RestoreDatabaseBackupAction::class)->replacingCurrent('backups_local', $path, 'backup_test');

        expect(file_get_contents($dir.'/logo.png'))->toBe('LOGO');
    } finally {
        File::deleteDirectory($dir);
    }
});

it('restores into a new database and folder without touching the current data (recommended path)', function () {
    $live = storage_path('app/public/backup-test-'.uniqid());
    mkdir($live, 0775, true);
    file_put_contents($live.'/photo.jpg', 'BEFORE');

    $config = config('backup_restore_test');
    $config['backup']['source']['files']['include'] = [$live];
    config(['backup_restore_test' => $config]);

    try {
        $path = backupNow();

        // Work continues after the backup: the live data must stay exactly as it is.
        DB::connection('backup_test')->table('sites')->where('code', 'M01')->update(['name' => 'Magasin 01 (modifié)']);
        file_put_contents($live.'/photo.jpg', 'AFTER');

        app(RestoreDatabaseBackupAction::class)->intoNewDatabase('backups_local', $path, RESTORED_DB, 'backup_test');

        config(['database.connections.restored' => [...config('database.connections.backup_test'), 'database' => RESTORED_DB]]);
        expect(siteNames('restored'))->toBe(['Dépôt Central', 'Magasin 01'])
            ->and(siteNames('backup_test'))->toBe(['Dépôt Central', 'Magasin 01 (modifié)'])
            ->and(file_get_contents($live.'/photo.jpg'))->toBe('AFTER')
            ->and(file_get_contents(RestoreDatabaseBackupAction::restoredFilesRoot().'/'.RESTORED_DB.'/'.basename($live).'/photo.jpg'))->toBe('BEFORE');
    } finally {
        File::deleteDirectory($live);
    }
});

it('refuses to restore into the current database or one that already exists', function () {
    $path = backupNow(['--only-db' => true]);
    $restore = app(RestoreDatabaseBackupAction::class);

    expect(fn () => $restore->intoNewDatabase('backups_local', $path, 'mouhim_backup_test', 'backup_test'))
        ->toThrow(RuntimeException::class, 'is the current database');

    DB::connection('pg_admin')->statement('CREATE DATABASE '.RESTORED_DB);
    expect(fn () => $restore->intoNewDatabase('backups_local', $path, RESTORED_DB, 'backup_test'))
        ->toThrow(RuntimeException::class, 'already exists');

    expect(fn () => $restore->intoNewDatabase('backups_local', $path, 'bad-name; DROP', 'backup_test'))
        ->toThrow(RuntimeException::class, 'Invalid database name');
});

it('changes nothing when the archive is damaged or the dump is truncated', function () {
    $path = backupNow(['--only-db' => true]);
    DB::connection('backup_test')->table('sites')->where('code', 'M01')->update(['name' => 'Après la sauvegarde']);
    $restore = app(RestoreDatabaseBackupAction::class);

    Storage::disk('backups_local')->put('not-a-zip.zip', 'garbage');
    expect(fn () => $restore->replacingCurrent('backups_local', 'not-a-zip.zip', 'backup_test'))
        ->toThrow(RuntimeException::class, 'not a valid archive');

    // An interrupted upload / download: the end of the dump is missing.
    $truncated = archiveWithDump($path, fn (string $sql) => substr($sql, 0, intdiv(strlen($sql), 2)));
    expect(fn () => $restore->replacingCurrent('backups_local', $truncated, 'backup_test'))
        ->toThrow(RuntimeException::class, 'does not contain a complete PostgreSQL dump');

    expect(siteNames('backup_test'))->toBe(['Dépôt Central', 'Après la sauvegarde']);
});

it('rolls everything back when the dump fails part-way through a replace', function () {
    $path = backupNow(['--only-db' => true]);
    DB::connection('backup_test')->table('sites')->where('code', 'M01')->update(['name' => 'Après la sauvegarde']);

    // Complete-looking dump that breaks after the schema was dropped and most data loaded.
    $broken = archiveWithDump($path, fn (string $sql) => str_replace(
        '-- PostgreSQL database dump complete',
        "SELECT * FROM table_that_does_not_exist;\n-- PostgreSQL database dump complete",
        $sql,
    ));

    expect(fn () => app(RestoreDatabaseBackupAction::class)->replacingCurrent('backups_local', $broken, 'backup_test'))
        ->toThrow(ProcessFailedException::class);

    // The old schema and data are all still there.
    expect(siteNames('backup_test'))->toBe(['Dépôt Central', 'Après la sauvegarde'])
        ->and(fn () => DB::connection('backup_test')->table('activity_log')->delete())->toThrow(QueryException::class);
});

it('removes the new database when restoring into it fails', function () {
    $path = backupNow(['--only-db' => true]);
    $broken = archiveWithDump($path, fn (string $sql) => str_replace(
        '-- PostgreSQL database dump complete',
        "SELECT * FROM table_that_does_not_exist;\n-- PostgreSQL database dump complete",
        $sql,
    ));

    expect(fn () => app(RestoreDatabaseBackupAction::class)->intoNewDatabase('backups_local', $broken, RESTORED_DB, 'backup_test'))
        ->toThrow(ProcessFailedException::class);

    expect(DB::connection('pg_admin')->table('pg_database')->where('datname', RESTORED_DB)->exists())->toBeFalse();
});

it('restores on the server given by DB_URL, not on the DB_HOST / DB_DATABASE defaults (audit)', function () {
    $base = config('database.connections.pgsql');
    // As on Railway: only DB_URL is set, the separate settings keep their (wrong) defaults.
    config(['database.connections.backup_test_url' => [...$base,
        'url' => sprintf('pgsql://%s:%s@%s:%s/mouhim_backup_test', $base['username'], $base['password'], $base['host'], $base['port']),
        'host' => '127.0.0.1', 'port' => '1', 'database' => 'laravel', 'username' => 'root', 'password' => '',
    ]]);

    $path = backupNow(['--only-db' => true]);
    DB::connection('backup_test')->table('sites')->delete();

    app(RestoreDatabaseBackupAction::class)->replacingCurrent('backups_local', $path, 'backup_test_url');

    expect(siteNames('backup_test'))->toBe(['Dépôt Central', 'Magasin 01']);
});

it('restores a password-protected archive and refuses a wrong password without changing anything', function () {
    $config = config('backup_restore_test');
    $config['backup']['password'] = 'archive-password-for-tests';
    config(['backup_restore_test' => $config, 'backup.backup.password' => 'archive-password-for-tests']);

    $path = backupNow(['--only-db' => true]);

    $zip = new ZipArchive;
    $zip->open(Storage::disk('backups_local')->path($path));
    expect($zip->statIndex(0)['encryption_method'])->not->toBe(ZipArchive::EM_NONE);
    $zip->close();

    DB::connection('backup_test')->table('sites')->where('code', 'M01')->update(['name' => 'Après la sauvegarde']);
    $restore = app(RestoreDatabaseBackupAction::class);

    config(['backup.backup.password' => 'wrong-password']);
    expect(fn () => $restore->replacingCurrent('backups_local', $path, 'backup_test'))->toThrow(RuntimeException::class);
    expect(siteNames('backup_test'))->toBe(['Dépôt Central', 'Après la sauvegarde']);

    config(['backup.backup.password' => 'archive-password-for-tests']);
    $restore->replacingCurrent('backups_local', $path, 'backup_test');
    expect(siteNames('backup_test'))->toBe(['Dépôt Central', 'Magasin 01']);
});

it('requires an explicit restore mode', function () {
    backupNow(['--only-db' => true]);

    $this->artisan('app:backup-restore', ['--force' => true])
        ->expectsOutputToContain('Choose one: --into=<new_database>')
        ->assertFailed();
});

it('never replaces the current database in production without a typed confirmation', function () {
    backupNow(['--only-db' => true]);
    app()->detectEnvironment(fn () => 'production');

    $this->artisan('app:backup-restore', ['--replace-current' => true, '--force' => true, '--no-interaction' => true])
        ->expectsOutputToContain('needs an interactive confirmation')
        ->assertFailed();

    expect(DB::table('sites')->count())->toBeGreaterThan(0);
});

/*
| Review of PR #8: database and files are two resources. Each failure point is injected and
| the exact resulting state is checked.
*/

/** A live folder of uploaded files included in the test backups. */
function liveFilesForBackup(string $content = 'PHOTO'): string
{
    $live = storage_path('app/public/backup-test-'.uniqid());
    mkdir($live.'/sub', 0775, true);
    file_put_contents($live.'/photo.jpg', $content);
    file_put_contents($live.'/sub/doc.pdf', $content.'-PDF');

    $config = config('backup_restore_test');
    $config['backup']['source']['files']['include'] = [$live];
    config(['backup_restore_test' => $config]);

    return $live;
}

/** Restore action whose given step fails, as a full disk or a broken mount would. */
function restoreFailingAt(string $step): RestoreDatabaseBackupAction
{
    return match ($step) {
        'copy' => new class extends RestoreDatabaseBackupAction
        {
            protected function copyVerified(string $source, string $target): void
            {
                mkdir($target, 0775, true);
                file_put_contents($target.'/half-copied.jpg', 'X');

                throw new RuntimeException('No space left on device (injected)');
            }
        },
        'move' => new class extends RestoreDatabaseBackupAction
        {
            protected function moveFiles(string $staging, string $target): void
            {
                throw new RuntimeException('No space left on device (injected)');
            }
        },
    };
}

function stagingFolders(): array
{
    return glob(storage_path('app/public/.restore-staging-*'), GLOB_ONLYDIR) ?: [];
}

it('changes neither the database nor the live files when the files cannot be put in place (replace)', function () {
    $live = liveFilesForBackup();
    try {
        $path = backupNow();
        DB::connection('backup_test')->table('sites')->where('code', 'M01')->update(['name' => 'Après la sauvegarde']);
        // A file now stands where the archive has a folder.
        $stagingBefore = stagingFolders();
        File::deleteDirectory($live);
        file_put_contents($live, 'NOT A FOLDER');

        expect(fn () => app(RestoreDatabaseBackupAction::class)->replacingCurrent('backups_local', $path, 'backup_test'))
            ->toThrow(RuntimeException::class, 'is a file where the archive has a folder');

        expect(siteNames('backup_test'))->toBe(['Dépôt Central', 'Après la sauvegarde'])
            ->and(file_get_contents($live))->toBe('NOT A FOLDER')
            ->and(stagingFolders())->toBe($stagingBefore);
    } finally {
        is_dir($live) ? File::deleteDirectory($live) : @unlink($live);
    }
});

it('changes neither the database nor the live files when copying the files fails (replace)', function () {
    $live = liveFilesForBackup();
    try {
        $path = backupNow();
        DB::connection('backup_test')->table('sites')->where('code', 'M01')->update(['name' => 'Après la sauvegarde']);
        file_put_contents($live.'/photo.jpg', 'CURRENT');
        $stagingBefore = stagingFolders();

        expect(fn () => restoreFailingAt('copy')->replacingCurrent('backups_local', $path, 'backup_test'))
            ->toThrow(RuntimeException::class, 'injected');

        expect(siteNames('backup_test'))->toBe(['Dépôt Central', 'Après la sauvegarde'])
            ->and(file_get_contents($live.'/photo.jpg'))->toBe('CURRENT')
            ->and(stagingFolders())->toBe($stagingBefore);
    } finally {
        File::deleteDirectory($live);
    }
});

it('says the database WAS replaced and keeps a verified copy when moving the files fails afterwards (replace)', function () {
    $live = liveFilesForBackup();
    try {
        $path = backupNow();
        DB::connection('backup_test')->table('sites')->where('code', 'M01')->update(['name' => 'Après la sauvegarde']);
        File::deleteDirectory($live);

        try {
            restoreFailingAt('move')->replacingCurrent('backups_local', $path, 'backup_test');
            $this->fail('A partial restore must not pass silently.');
        } catch (PartialRestoreException $e) {
            expect($e->getMessage())->toContain('The database WAS restored');
            $staged = $e->stagedFiles;
        }

        // Documented state: database restored, live files missing, complete copy kept for the retry.
        expect(siteNames('backup_test'))->toBe(['Dépôt Central', 'Magasin 01'])
            ->and(is_dir($live))->toBeFalse()
            ->and(file_get_contents($staged.'/'.basename($live).'/photo.jpg'))->toBe('PHOTO')
            ->and(file_get_contents($staged.'/'.basename($live).'/sub/doc.pdf'))->toBe('PHOTO-PDF');
        File::deleteDirectory($staged);
    } finally {
        File::deleteDirectory($live);
    }
});

it('removes both the new database and the new files folder when restoring into them fails', function (string $step) {
    $live = liveFilesForBackup();
    try {
        $path = backupNow();
        $restore = $step === 'copy' ? restoreFailingAt('copy') : app(RestoreDatabaseBackupAction::class);
        if ($step === 'database') {
            $path = archiveWithDump($path, fn (string $sql) => str_replace(
                '-- PostgreSQL database dump complete',
                "SELECT * FROM table_that_does_not_exist;\n-- PostgreSQL database dump complete",
                $sql,
            ));
            // The tampered archive keeps the files of the original one.
            $zip = new ZipArchive;
            $zip->open(Storage::disk('backups_local')->path($path));
            $zip->addFromString('public/'.basename($live).'/photo.jpg', 'PHOTO');
            $zip->close();
        }

        expect(fn () => $restore->intoNewDatabase('backups_local', $path, RESTORED_DB, 'backup_test'))->toThrow(RuntimeException::class);

        expect(DB::connection('pg_admin')->table('pg_database')->where('datname', RESTORED_DB)->exists())->toBeFalse()
            ->and(file_exists(RestoreDatabaseBackupAction::restoredFilesRoot().'/'.RESTORED_DB))->toBeFalse()
            ->and(file_get_contents($live.'/photo.jpg'))->toBe('PHOTO');
    } finally {
        File::deleteDirectory($live);
    }
})->with(['copy', 'database']);

it('never lets a restore into a new database write over the live files', function (string $target) {
    $path = backupNow(['--only-db' => true]);

    expect(fn () => app(RestoreDatabaseBackupAction::class)->intoNewDatabase('backups_local', $path, RESTORED_DB, 'backup_test', filesTarget: $target))
        ->toThrow(RuntimeException::class, 'overlaps the live uploaded files');

    expect(DB::connection('pg_admin')->table('pg_database')->where('datname', RESTORED_DB)->exists())->toBeFalse();
})->with([
    'the live folder' => fn () => storage_path('app/public'),
    'its parent' => fn () => storage_path('app'),
    'a live sub-folder' => fn () => storage_path('app/public/used-tyres'),
    'a path escaping _restored' => fn () => storage_path('app/public/_restored/../used-tyres'),
    'a relative path to it' => 'storage/app/public',
]);

it('keeps restored files on the persistent volume, apart from the live files, and out of the backups', function () {
    expect(RestoreDatabaseBackupAction::restoredFilesRoot())->toStartWith(storage_path('app/public').'/');

    $copy = RestoreDatabaseBackupAction::restoredFilesRoot().'/old_restore';
    $live = storage_path('app/public/backup-test-'.uniqid());
    mkdir($copy, 0775, true);
    mkdir($live, 0775, true);
    file_put_contents($copy.'/restored.jpg', 'COPY');
    file_put_contents($live.'/live.jpg', 'LIVE');

    // The real include / exclude settings, written to the test disk.
    $config = config('backup_restore_test');
    $config['backup']['source']['files'] = config('backup.backup.source.files');
    config(['backup_restore_test' => $config]);

    try {
        $path = backupNow();
        $zip = new ZipArchive;
        $zip->open(Storage::disk('backups_local')->path($path));
        $names = collect(range(0, $zip->numFiles - 1))->map(fn ($i) => $zip->getNameIndex($i));
        $zip->close();

        expect($names->contains('public/'.basename($live).'/live.jpg'))->toBeTrue()
            ->and($names->contains(fn ($n) => str_contains($n, '_restored')))->toBeFalse();
    } finally {
        File::deleteDirectory($live);
    }
});

it('explains when the database user may not create the new database', function () {
    DB::connection('pg_admin')->statement('DROP ROLE IF EXISTS restore_no_createdb');
    DB::connection('pg_admin')->statement("CREATE ROLE restore_no_createdb LOGIN PASSWORD 'secret-for-tests' NOCREATEDB");
    config(['database.connections.no_createdb' => [...config('database.connections.backup_test'), 'username' => 'restore_no_createdb', 'password' => 'secret-for-tests']]);

    try {
        $path = backupNow(['--only-db' => true]);

        expect(fn () => app(RestoreDatabaseBackupAction::class)->intoNewDatabase('backups_local', $path, RESTORED_DB, 'no_createdb'))
            ->toThrow(RuntimeException::class, 'may not create databases (CREATEDB)');
    } finally {
        DB::purge('no_createdb');
        DB::connection('pg_admin')->statement('DROP ROLE IF EXISTS restore_no_createdb');
    }
});
