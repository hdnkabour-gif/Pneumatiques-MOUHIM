<?php

use App\Actions\Backups\RestoreDatabaseBackupAction;
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
    File::deleteDirectory(storage_path('app/restored'));
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

it('schedules the daily backup', function () {
    $events = collect(app(Schedule::class)->events())
        ->map(fn ($e) => $e->command);

    expect($events->contains(fn ($c) => str_contains($c, 'backup:run')))->toBeTrue()
        ->and($events->contains(fn ($c) => str_contains($c, 'backup:clean')))->toBeTrue();
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
            ->and(file_get_contents(storage_path('app/restored/'.RESTORED_DB.'/public/'.basename($live).'/photo.jpg')))->toBe('BEFORE');
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
