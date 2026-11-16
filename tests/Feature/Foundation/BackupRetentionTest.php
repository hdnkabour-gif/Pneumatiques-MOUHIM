<?php

use App\Services\Backups\BackupAlerts;
use Illuminate\Console\Scheduling\Event;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Storage;

/*
| Review of PR #8: backup:clean ran at 01:30 even when the backups of the previous nights had
| failed. Old backups are now cleaned only right after a successful backup.
| Uses spatie's real cleanup strategy (30 days all, then daily / weekly / monthly / 2 years).
*/

beforeEach(function () {
    Storage::fake('backups_local'); // BACKUP_DISK in the test environment
});

/** Archives named like spatie's (Y-m-d-H-i-s.zip): their name gives their date. */
function storeBackupsAged(int ...$days): void
{
    foreach ($days as $age) {
        Storage::disk('backups_local')->put('pneu-mouhim/'.now()->subDays($age)->format('Y-m-d-H-i-s').'.zip', 'archive');
    }
}

function storedBackupAges(): array
{
    return collect(Storage::disk('backups_local')->files('pneu-mouhim'))
        ->map(fn (string $path) => (int) round(now()->diffInDays(Carbon\Carbon::createFromFormat('Y-m-d-H-i-s', basename($path, '.zip')), true)))
        ->sort()->values()->all();
}

function nightlyBackup(int $exitCode): void
{
    $event = collect(app(Schedule::class)->events())->first(fn (Event $e) => str_contains((string) $e->command, 'backup:run'));
    $event->exitCode = $exitCode;
    $event->callAfterCallbacks(app());
}

it('keeps every existing backup when the nightly backup fails', function () {
    $owner = owner();
    storeBackupsAged(1, 5, 40, 1100);

    nightlyBackup(1);

    expect(storedBackupAges())->toBe([1, 5, 40, 1100])
        ->and($owner->notifications()->count())->toBe(1);
});

it('cleans old backups after a successful nightly backup, following the retention rules', function () {
    storeBackupsAged(0, 5, 29, 1100);

    nightlyBackup(0);

    // Everything under 30 days is kept; the 3-year-old archive is removed.
    expect(storedBackupAges())->toBe([0, 5, 29]);
});

it('never deletes the newest backup, however old it is', function () {
    storeBackupsAged(1100, 1500);

    expect(Artisan::call('backup:clean', ['--disable-notifications' => true]))->toBe(0)
        ->and(storedBackupAges())->toBe([1100]);
});

it('alerts the owners when the cleanup after a successful backup fails', function () {
    $owner = owner();
    Artisan::shouldReceive('call')->once()->with('backup:clean')->andReturn(1);

    app(BackupAlerts::class)->cleanAfterSuccessfulBackup();

    expect($owner->notifications()->first()?->data['body'])->toContain('nettoyage des anciennes sauvegardes');
});
