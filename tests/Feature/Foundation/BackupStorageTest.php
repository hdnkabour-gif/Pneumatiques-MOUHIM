<?php

use Illuminate\Filesystem\FilesystemAdapter;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Storage;
use Spatie\Backup\Events\BackupHasFailed;

/*
| Audit 16/11/2026: with the BACKUP_S3_* variables absent, the off-site disk crashed with a
| TypeError, which spatie/laravel-backup does not catch: backup:run and backup:clean exited
| with code 1 and no "backup has failed" notification could ever be sent.
*/

function unconfigureBackupDisk(): void
{
    config()->set('filesystems.disks.backups', [
        ...config('filesystems.disks.backups'),
        'bucket' => null, 'endpoint' => null, 'key' => null, 'secret' => null,
    ]);
    Storage::forgetDisk('backups');
}

it('names the missing variables when the off-site disk is not configured', function () {
    unconfigureBackupDisk();

    expect(fn () => Storage::disk('backups'))->toThrow(
        RuntimeException::class,
        'Off-site backup storage is not configured. Missing: BACKUP_S3_BUCKET, BACKUP_S3_ENDPOINT, BACKUP_S3_KEY, BACKUP_S3_SECRET.',
    );
});

it('reports a failed backup, instead of crashing, when the off-site disk is not configured', function () {
    unconfigureBackupDisk();
    Event::fake([BackupHasFailed::class]);

    $backupConfig = config('backup');
    $backupConfig['backup']['destination']['disks'] = ['backups'];
    config(['backup_storage_test' => $backupConfig]);

    expect(Artisan::call('backup:run', ['--only-db' => true, '--config' => 'backup_storage_test']))->toBe(1);

    Event::assertDispatched(BackupHasFailed::class, fn (BackupHasFailed $event) => str_contains($event->exception->getMessage(), 'BACKUP_S3_BUCKET'));
});

it('builds the off-site disk when every setting is present', function () {
    config()->set('filesystems.disks.backups', [
        ...config('filesystems.disks.backups'),
        'bucket' => 'test-bucket', 'endpoint' => 'https://storage.invalid', 'key' => 'test-key', 'secret' => 'test-secret',
    ]);
    Storage::forgetDisk('backups');

    expect(Storage::disk('backups'))->toBeInstanceOf(FilesystemAdapter::class);
});
