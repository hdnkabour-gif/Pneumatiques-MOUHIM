<?php

use App\Actions\Sales\QuoteActions;
use App\Services\Backups\BackupAlerts;
use Illuminate\Support\Facades\Schedule;

/*
| Daily off-Railway backup (§19): database + uploaded files, kept ≥ 30 days.
| Times are Africa/Casablanca. Runs in the "scheduler" container.
| Old backups are cleaned only right after a successful backup, so nights where the backup
| fails never thin out the existing ones. Any failure (crash, failed backup or cleanup,
| backup too old) alerts the owners.
*/
Schedule::command('backup:run')->dailyAt('02:00')->timezone(config('mouhim.timezone'))->onOneServer()
    ->onSuccess(fn () => app(BackupAlerts::class)->cleanAfterSuccessfulBackup())
    ->onFailure(fn () => app(BackupAlerts::class)->taskFailed('backup:run'));
Schedule::command('backup:monitor')->dailyAt('08:00')->timezone(config('mouhim.timezone'))->onOneServer()
    ->onFailure(fn () => app(BackupAlerts::class)->taskFailed('backup:monitor'));

// Quotes past their validity are expired and their reserved stock released (§10.3, D-13).
Schedule::call(fn () => app(QuoteActions::class)->expireOld())
    ->name('expire-quotes')->dailyAt('00:15')->timezone(config('mouhim.timezone'))->onOneServer();

// Evening summary to the owner (§16.2), 20:00 by default.
Schedule::command('reports:evening-summary')->dailyAt(config('mouhim.evening_summary_at'))
    ->timezone(config('mouhim.timezone'))->onOneServer();
