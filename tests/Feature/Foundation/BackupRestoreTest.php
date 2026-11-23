<?php

use App\Actions\Backups\RestoreDatabaseBackupAction;
use App\Exceptions\PartialRestoreException;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Database\QueryException;
use Illuminate\Process\Exceptions\ProcessFailedException;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Log;
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
    // A unique name: spatie names archives by the second, so two backups in the same second
    // would overwrite each other and "the newest" would be ambiguous.
    $filename = 'backup-'.uniqid().'.zip';
    expect(Artisan::call('backup:run', ['--config' => 'backup_restore_test', '--disable-notifications' => true, '--filename' => $filename, ...$options]))->toBe(0);

    return collect(app(RestoreDatabaseBackupAction::class)->available('backups_local'))
        ->sole(fn (string $path) => basename($path) === $filename);
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
| Reviews of PR #8: database and files are two resources. Each failure point is injected and
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

/** Restore action whose given step fails, as a full disk, a broken mount or a bad copy would. */
function restoreFailingAt(string $step): RestoreDatabaseBackupAction
{
    return match ($step) {
        // --into: the copy of the files into the new private folder fails half-way.
        'copy' => new class extends RestoreDatabaseBackupAction
        {
            protected function copyVerified(string $source, string $target): void
            {
                mkdir($target, 0700, true);
                file_put_contents($target.'/half-copied.jpg', 'X');

                throw new RuntimeException('No space left on device (injected)');
            }
        },
        // A copy silently altered (same size, different bytes): the SHA-256 check must catch it.
        'corrupt-tree' => new class extends RestoreDatabaseBackupAction
        {
            protected function copyTree(string $source, string $target): void
            {
                parent::copyTree($source, $target);
                // One byte changed, same size.
                $file = collect(File::allFiles($target))->first()->getPathname();
                $content = file_get_contents($file);
                $content[0] = $content[0] === 'Z' ? 'Y' : 'Z';
                file_put_contents($file, $content);
            }
        },
        'corrupt-file' => new class extends RestoreDatabaseBackupAction
        {
            protected function copyFile(string $from, string $to): void
            {
                parent::copyFile($from, $to);
                $content = file_get_contents($to);
                $content[0] = $content[0] === 'Z' ? 'Y' : 'Z'; // one byte changed, same size
                file_put_contents($to, $content);
            }
        },
        // --into: removing the new database fails during the cleanup.
        'drop' => new class extends RestoreDatabaseBackupAction
        {
            protected function dropDatabase(?string $connection, string $database): void
            {
                throw new RuntimeException('Cannot drop the database (injected)');
            }
        },
    };
}

/** Restore action whose second file fails to be put in place, after the first one succeeded. */
function restoreFailingOnSecondFile(): RestoreDatabaseBackupAction
{
    return new class extends RestoreDatabaseBackupAction
    {
        public int $calls = 0;

        protected function placeFile(string $from, string $to, string $sha256): void
        {
            if (++$this->calls === 2) {
                throw new RuntimeException('No space left on device (injected on file 2)');
            }
            parent::placeFile($from, $to, $sha256);
        }
    };
}

/** Temporary copies left anywhere in the public web tree. */
function leftoversInPublicTree(): array
{
    return collect(File::allFiles(storage_path('app/public'), true))
        ->map(fn ($f) => $f->getRelativePathname())
        ->filter(fn ($p) => str_contains($p, '.restore-'))
        ->values()->all();
}

it('changes neither the database nor the live files when the files cannot be put in place (replace)', function () {
    $live = liveFilesForBackup();
    try {
        $path = backupNow();
        DB::connection('backup_test')->table('sites')->where('code', 'M01')->update(['name' => 'Après la sauvegarde']);
        // A file now stands where the archive has a folder.
        File::deleteDirectory($live);
        file_put_contents($live, 'NOT A FOLDER');

        expect(fn () => app(RestoreDatabaseBackupAction::class)->replacingCurrent('backups_local', $path, 'backup_test'))
            ->toThrow(RuntimeException::class, 'is a file where the archive has a folder');

        expect(siteNames('backup_test'))->toBe(['Dépôt Central', 'Après la sauvegarde'])
            ->and(file_get_contents($live))->toBe('NOT A FOLDER')
            ->and(leftoversInPublicTree())->toBe([]);
    } finally {
        is_dir($live) ? File::deleteDirectory($live) : @unlink($live);
    }
});

it('says exactly how far it got when file 2 fails after file 1 was put in place, and the archive finishes the job (replace)', function () {
    $live = liveFilesForBackup();
    try {
        $path = backupNow();
        DB::connection('backup_test')->table('sites')->where('code', 'M01')->update(['name' => 'Après la sauvegarde']);
        File::deleteDirectory($live);

        try {
            restoreFailingOnSecondFile()->replacingCurrent('backups_local', $path, 'backup_test');
            $this->fail('A partial restore must not pass silently.');
        } catch (PartialRestoreException $e) {
            expect($e->getMessage())->toContain('The database WAS restored')
                ->toContain('only 1 of 2 uploaded files')
                ->toContain('app:backup-restore '.escapeshellarg($path).' --files-only --disk='.escapeshellarg('backups_local').' --files-to='.escapeshellarg(storage_path('app/public')))
                ->and([$e->placed, $e->total])->toBe([1, 2]);
        }

        // Documented state: database restored, file 1 in place (complete, never half-written), file 2 missing.
        $placed = collect(['photo.jpg', 'sub/doc.pdf'])->filter(fn ($f) => file_exists("{$live}/{$f}"))->values()->all();
        expect(siteNames('backup_test'))->toBe(['Dépôt Central', 'Magasin 01'])
            ->and($placed)->toHaveCount(1)
            ->and(file_get_contents("{$live}/{$placed[0]}"))->toBe($placed[0] === 'photo.jpg' ? 'PHOTO' : 'PHOTO-PDF')
            ->and(leftoversInPublicTree())->toBe([]);

        // The recovery named in the message, from the unchanged archive, completes the files.
        app(RestoreDatabaseBackupAction::class)->filesOnly('backups_local', $path);
        expect(file_get_contents($live.'/photo.jpg'))->toBe('PHOTO')
            ->and(file_get_contents($live.'/sub/doc.pdf'))->toBe('PHOTO-PDF')
            ->and(siteNames('backup_test'))->toBe(['Dépôt Central', 'Magasin 01'])
            ->and(leftoversInPublicTree())->toBe([]);
    } finally {
        File::deleteDirectory($live);
    }
});

it('never replaces a live file with a copy that does not match the archive (SHA-256)', function () {
    $live = liveFilesForBackup('ORIGINAL');
    try {
        $path = backupNow();
        file_put_contents($live.'/photo.jpg', 'CURRENT');
        file_put_contents($live.'/sub/doc.pdf', 'CURRENT-PDF');

        expect(fn () => restoreFailingAt('corrupt-file')->filesOnly('backups_local', $path))
            ->toThrow(RuntimeException::class, 'does not match the archive (SHA-256)');

        expect(file_get_contents($live.'/photo.jpg'))->toBe('CURRENT')
            ->and(file_get_contents($live.'/sub/doc.pdf'))->toBe('CURRENT-PDF')
            ->and(leftoversInPublicTree())->toBe([]);
    } finally {
        File::deleteDirectory($live);
    }
});

it('removes both the new database and the new files folder when restoring into them fails', function (string $step) {
    $live = liveFilesForBackup();
    try {
        $path = backupNow();
        $restore = in_array($step, ['copy', 'corrupt-tree'], true) ? restoreFailingAt($step) : app(RestoreDatabaseBackupAction::class);
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
})->with(['copy', 'corrupt-tree', 'database']);

it('still removes the new files folder, reports the leftover database and keeps the original error when the database cannot be dropped', function () {
    $live = liveFilesForBackup();
    Log::spy();
    try {
        $path = archiveWithDump(backupNow(), fn (string $sql) => str_replace(
            '-- PostgreSQL database dump complete',
            "SELECT * FROM table_that_does_not_exist;\n-- PostgreSQL database dump complete",
            $sql,
        ));
        $zip = new ZipArchive;
        $zip->open(Storage::disk('backups_local')->path($path));
        $zip->addFromString('public/'.basename($live).'/photo.jpg', 'PHOTO');
        $zip->close();

        expect(fn () => restoreFailingAt('drop')->intoNewDatabase('backups_local', $path, RESTORED_DB, 'backup_test'))
            ->toThrow(ProcessFailedException::class); // the original error, not the cleanup one

        expect(file_exists(RestoreDatabaseBackupAction::restoredFilesRoot().'/'.RESTORED_DB))->toBeFalse();
        Log::shouldHaveReceived('error')->withArgs(fn ($message) => str_contains($message, 'could not remove: database ['.RESTORED_DB.']'))->once();
    } finally {
        File::deleteDirectory($live);
    }
});

it('never writes restored copies into the public web tree', function (string $target) {
    $path = backupNow(['--only-db' => true]);

    expect(fn () => app(RestoreDatabaseBackupAction::class)->intoNewDatabase('backups_local', $path, RESTORED_DB, 'backup_test', filesTarget: $target))
        ->toThrow(RuntimeException::class, 'public web tree');

    expect(DB::connection('pg_admin')->table('pg_database')->where('datname', RESTORED_DB)->exists())->toBeFalse();
})->with([
    'the live folder' => fn () => storage_path('app/public'),
    'its parent' => fn () => storage_path('app'),
    'a live sub-folder' => fn () => storage_path('app/public/used-tyres'),
    'the old _restored folder' => fn () => storage_path('app/public/_restored/db'),
    'a path escaping the private folder' => fn () => storage_path('app/private/restored/../../public/x'),
    'a relative path to it' => 'storage/app/public/x',
    'the document root' => fn () => public_path('restored'),
]);

it('keeps restored copies private: outside the public tree, even with the storage link, and refused over HTTP', function () {
    $private = RestoreDatabaseBackupAction::restoredFilesRoot();
    $public = realpath(storage_path('app/public'));

    // With the usual "php artisan storage:link", only storage/app/public is reachable as /storage.
    expect(str_starts_with($private.'/', $public.'/'))->toBeFalse()
        ->and(str_starts_with($private.'/', public_path().'/'))->toBeFalse();

    // A restored copy is not served, not even through the private disk's route (signed URLs only).
    $copy = $private.'/'.RESTORED_DB.'/used-tyres/receipt.jpg';
    File::ensureDirectoryExists(dirname($copy));
    file_put_contents($copy, 'PRIVATE');

    foreach (['/storage/restored/'.RESTORED_DB.'/used-tyres/receipt.jpg', '/storage/app/private/restored/'.RESTORED_DB.'/used-tyres/receipt.jpg'] as $url) {
        expect($this->get($url)->status())->toBeIn([403, 404]);
    }
});

it('gives a finishing command that keeps the disk and the folder actually used (custom disk and target)', function () {
    $live = liveFilesForBackup();
    $target = storage_path('app/private/custom-target-'.uniqid());
    Storage::fake('backups_custom');
    $config = config('backup_restore_test');
    $config['backup']['destination']['disks'] = ['backups_custom'];
    config(['backup_restore_test' => $config]);

    try {
        expect(Artisan::call('backup:run', ['--config' => 'backup_restore_test', '--disable-notifications' => true]))->toBe(0);
        $path = app(RestoreDatabaseBackupAction::class)->available('backups_custom')[0];

        try {
            restoreFailingOnSecondFile()->replacingCurrent('backups_custom', $path, 'backup_test', filesTarget: $target);
            $this->fail('A partial restore must not pass silently.');
        } catch (PartialRestoreException $e) {
            expect($e->getMessage())->toContain($e->finishCommand())
                ->and($e->finishCommand())->toBe('php artisan app:backup-restore '.escapeshellarg($path)
                    .' --files-only --disk='.escapeshellarg('backups_custom').' --files-to='.escapeshellarg($target));
        }

        // Running exactly that command (disk and target from the message) finishes the custom folder.
        $this->artisan('app:backup-restore', ['path' => $path, '--files-only' => true, '--disk' => $e->disk, '--files-to' => $e->target, '--force' => true])
            ->assertSuccessful();
        expect(file_get_contents($target.'/'.basename($live).'/photo.jpg'))->toBe('PHOTO')
            ->and(file_get_contents($target.'/'.basename($live).'/sub/doc.pdf'))->toBe('PHOTO-PDF');
    } finally {
        File::deleteDirectory($live);
        File::deleteDirectory($target);
    }
});

it('saves the files of an archive whose database dump is damaged or missing (--files-only), still refusing an unreadable archive', function () {
    $live = liveFilesForBackup();
    try {
        $path = backupNow();
        // Same files, but the dump is cut in half (or absent).
        $truncated = archiveWithDump($path, fn (string $sql) => substr($sql, 0, intdiv(strlen($sql), 2)));
        $zip = new ZipArchive;
        $zip->open(Storage::disk('backups_local')->path($truncated));
        $zip->addFromString('public/'.basename($live).'/photo.jpg', 'PHOTO');
        $zip->close();
        $filesOnlyArchive = 'files-only.zip';
        $zip->open(Storage::disk('backups_local')->path($filesOnlyArchive), ZipArchive::CREATE);
        $zip->addFromString('public/'.basename($live).'/sub/doc.pdf', 'PHOTO-PDF');
        $zip->close();
        File::deleteDirectory($live);
        $restore = app(RestoreDatabaseBackupAction::class);

        // The database modes still refuse the damaged dump…
        expect(fn () => $restore->replacingCurrent('backups_local', $truncated, 'backup_test'))
            ->toThrow(RuntimeException::class, 'does not contain a complete PostgreSQL dump');
        expect(is_dir($live))->toBeFalse();

        // …but the files can be saved, from either archive.
        $restore->filesOnly('backups_local', $truncated);
        $restore->filesOnly('backups_local', $filesOnlyArchive);
        expect(file_get_contents($live.'/photo.jpg'))->toBe('PHOTO')
            ->and(file_get_contents($live.'/sub/doc.pdf'))->toBe('PHOTO-PDF');

        // An archive that does not open, or has no uploaded files, is still refused.
        Storage::disk('backups_local')->put('not-a-zip.zip', 'garbage');
        expect(fn () => $restore->filesOnly('backups_local', 'not-a-zip.zip'))->toThrow(RuntimeException::class, 'not a valid archive');
        expect(fn () => $restore->filesOnly('backups_local', backupNow(['--only-db' => true])))->toThrow(RuntimeException::class, 'contains no uploaded files');
    } finally {
        File::deleteDirectory($live);
    }
});

it('never puts archive files over the live files in production without a person confirming', function () {
    $live = liveFilesForBackup('ARCHIVE');
    try {
        backupNow();
        file_put_contents($live.'/photo.jpg', 'CURRENT');
        app()->detectEnvironment(fn () => 'production');

        $this->artisan('app:backup-restore', ['--files-only' => true, '--force' => true, '--disk' => 'backups_local', '--no-interaction' => true])
            ->expectsOutputToContain('needs an interactive confirmation')
            ->assertFailed();

        expect(file_get_contents($live.'/photo.jpg'))->toBe('CURRENT');
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

/*
 * Private documents (expense receipts, storage/app/documents) are backed up next to the public
 * files and come back in every restore mode, verified file by file; they are never restored
 * into the public web tree.
 */
it('backs up the private documents and restores them in every mode, never into the public tree', function () {
    $public = storage_path('app/public/backup-test-'.uniqid());
    $documents = storage_path('app/documents/backup-test-'.uniqid());
    mkdir($public, 0775, true);
    mkdir($documents.'/expenses', 0775, true);
    file_put_contents($public.'/logo.png', 'LOGO');
    file_put_contents($documents.'/expenses/receipt.jpg', 'RECEIPT');

    $config = config('backup_restore_test');
    $config['backup']['source']['files']['include'] = [$public, $documents];
    $config['backup']['source']['files']['relative_path'] = storage_path('app');
    config(['backup_restore_test' => $config]);

    try {
        $path = backupNow();
        $action = app(RestoreDatabaseBackupAction::class);

        // 1. Into a new database: documents go to their own private folder.
        $action->intoNewDatabase('backups_local', $path, RESTORED_DB, 'backup_test');
        $restoredDocs = RestoreDatabaseBackupAction::restoredFilesRoot().'/'.RESTORED_DB.'-documents';
        expect(file_get_contents($restoredDocs.'/'.basename($documents).'/expenses/receipt.jpg'))->toBe('RECEIPT')
            ->and(glob(storage_path('app/public').'/**/receipt.jpg'))->toBe([]);

        // 2. Replacing the current data after losing the receipt: it comes back in place.
        unlink($documents.'/expenses/receipt.jpg');
        $action->replacingCurrent('backups_local', $path, 'backup_test');
        expect(file_get_contents($documents.'/expenses/receipt.jpg'))->toBe('RECEIPT')
            ->and(file_get_contents($public.'/logo.png'))->toBe('LOGO');

        // 3. Files only (finishing a partial restore): the receipt comes back too.
        unlink($documents.'/expenses/receipt.jpg');
        $action->filesOnly('backups_local', $path);
        expect(file_get_contents($documents.'/expenses/receipt.jpg'))->toBe('RECEIPT');

        // A documents target inside the public web tree is refused.
        DB::connection('pg_admin')->statement('DROP DATABASE IF EXISTS '.RESTORED_DB.' WITH (FORCE)');
        expect(fn () => $action->intoNewDatabase('backups_local', $path, RESTORED_DB, 'backup_test', filesTarget: RestoreDatabaseBackupAction::restoredFilesRoot().'/fresh-'.uniqid(), documentsTarget: storage_path('app/public/leak')))
            ->toThrow(RuntimeException::class, 'public web tree');
    } finally {
        File::deleteDirectory($public);
        File::deleteDirectory($documents);
    }
});

it('includes the private documents folder in the production backup configuration', function () {
    expect(config('backup.backup.source.files.include'))->toContain(storage_path('app/documents'))
        ->and(config('backup.backup.source.files.include'))->toContain(storage_path('app/public'));
});
