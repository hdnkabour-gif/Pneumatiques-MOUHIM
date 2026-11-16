<?php

use App\Actions\Sales\QuoteActions;
use App\Services\Backups\BackupAlerts;
use Illuminate\Support\Facades\Schedule;

/*
| Daily off-Railway backup (§19): database + uploaded files, kept ≥ 30 days.
| Times are Africa/Casablanca. Runs in the "scheduler" container.
| Any failure (crash, failed backup, backup too old) alerts the owners.
*/
foreach (['backup:clean' => '01:30', 'backup:run' => '02:00', 'backup:monitor' => '08:00'] as $task => $time) {
    Schedule::command($task)->dailyAt($time)->timezone(config('mouhim.timezone'))->onOneServer()
        ->onFailure(fn () => app(BackupAlerts::class)->taskFailed($task));
}

// Quotes past their validity are expired and their reserved stock released (§10.3, D-13).
Schedule::call(fn () => app(QuoteActions::class)->expireOld())
    ->name('expire-quotes')->dailyAt('00:15')->timezone(config('mouhim.timezone'))->onOneServer();

// Evening summary to the owner (§16.2), 20:00 by default.
Schedule::command('reports:evening-summary')->dailyAt(config('mouhim.evening_summary_at'))
    ->timezone(config('mouhim.timezone'))->onOneServer();
