<?php

use App\Actions\Backups\RestoreDatabaseBackupAction;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Storage;

/*
| Phase 1 acceptance: "sauvegarde restaurée avec succès".
| Runs against a dedicated scratch database, outside the test transaction,
| so pg_dump / psql see real committed data.
*/

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

    DB::connection('pg_admin')->statement('DROP DATABASE IF EXISTS mouhim_backup_test WITH (FORCE)');
    DB::connection('pg_admin')->statement('CREATE DATABASE mouhim_backup_test');

    Artisan::call('migrate', ['--database' => 'backup_test', '--force' => true]);
    Storage::fake('backups_local');
});

afterEach(function () {
    DB::connection('backup_test')->disconnect();
    DB::connection('pg_admin')->statement('DROP DATABASE IF EXISTS mouhim_backup_test WITH (FORCE)');
});

it('backs up the database and restores it after data loss', function () {
    $db = DB::connection('backup_test');
    $db->table('sites')->insert([
        ['code' => 'DEP', 'name' => 'Dépôt Central', 'type' => 'depot', 'created_at' => now(), 'updated_at' => now()],
        ['code' => 'M01', 'name' => 'Magasin 01', 'type' => 'store', 'created_at' => now(), 'updated_at' => now()],
    ]);
    $db->table('activity_log')->insert(['description' => 'avant sauvegarde', 'created_at' => now(), 'updated_at' => now()]);

    expect(Artisan::call('backup:run', ['--only-db' => true, '--config' => 'backup_restore_test', '--disable-notifications' => true]))->toBe(0);

    $restore = app(RestoreDatabaseBackupAction::class);
    $backups = $restore->available('backups_local');
    expect($backups)->toHaveCount(1);

    // Disaster: data lost after the backup.
    $db->table('sites')->delete();
    expect($db->table('sites')->count())->toBe(0);

    $restore->execute('backups_local', $backups[0], 'backup_test');

    $db->reconnect();
    expect($db->table('sites')->orderBy('code')->pluck('name')->all())->toBe(['Dépôt Central', 'Magasin 01'])
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
        expect(Artisan::call('backup:run', ['--config' => 'backup_restore_test', '--disable-notifications' => true]))->toBe(0);
        unlink($dir.'/logo.png'); // disaster: the file is lost

        $restore = app(RestoreDatabaseBackupAction::class);
        $restore->execute('backups_local', $restore->available('backups_local')[0], 'backup_test');

        expect(file_get_contents($dir.'/logo.png'))->toBe('LOGO');
    } finally {
        File::deleteDirectory($dir);
    }
});
