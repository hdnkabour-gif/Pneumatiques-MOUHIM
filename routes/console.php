<?php

use App\Actions\Sales\QuoteActions;
use Illuminate\Support\Facades\Schedule;

/*
| Daily off-Railway backup (§19): database + uploaded files, kept ≥ 30 days.
| Times are Africa/Casablanca. Runs in the "scheduler" container.
*/
Schedule::command('backup:clean')->dailyAt('01:30')->timezone(config('mouhim.timezone'))->onOneServer();
Schedule::command('backup:run')->dailyAt('02:00')->timezone(config('mouhim.timezone'))->onOneServer();
Schedule::command('backup:monitor')->dailyAt('08:00')->timezone(config('mouhim.timezone'))->onOneServer();

// Quotes past their validity are expired and their reserved stock released (§10.3, D-13).
Schedule::call(fn () => app(QuoteActions::class)->expireOld())
    ->name('expire-quotes')->dailyAt('00:15')->timezone(config('mouhim.timezone'))->onOneServer();
