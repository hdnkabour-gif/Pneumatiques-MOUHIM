<?php

use App\Enums\RoleName;
use Illuminate\Console\Scheduling\Event;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Support\Facades\DB;

/*
| Audit 16/11/2026: backup:clean and backup:run failed every night (exit code 1) and nobody
| was told. Every failure of a scheduled backup task must reach the owners.
*/

beforeEach(function () {
    // As in production: queued jobs wait for a worker. The alert must not depend on one.
    config(['queue.default' => 'database']);
});

function backupTaskEvent(string $task): Event
{
    return collect(app(Schedule::class)->events())->first(fn (Event $e) => str_contains((string) $e->command, $task));
}

it('alerts every active owner, in their language, when a scheduled backup task fails', function (string $task) {
    $owner = owner();
    $arabicOwner = owner();
    $arabicOwner->update(['locale' => 'ar']);
    $formerOwner = owner();
    $formerOwner->update(['is_active' => false]);
    $seller = userWithRole(RoleName::Seller);

    $event = backupTaskEvent($task);
    $event->exitCode = 1;
    $event->callAfterCallbacks(app());

    expect($owner->notifications()->count())->toBe(1)
        ->and($owner->notifications()->first()->data['title'])->toBe('Sauvegarde : échec')
        ->and($arabicOwner->notifications()->first()->data['title'])->toBe('النسخ الاحتياطي: فشل')
        ->and($formerOwner->notifications()->count())->toBe(0)
        ->and($seller->notifications()->count())->toBe(0)
        ->and(DB::table('jobs')->count())->toBe(0);
})->with(['backup:run', 'backup:clean', 'backup:monitor']);

it('stays silent when the backup task succeeds', function () {
    $owner = owner();

    $event = backupTaskEvent('backup:run');
    $event->exitCode = 0;
    $event->callAfterCallbacks(app());

    expect($owner->notifications()->count())->toBe(0);
});
