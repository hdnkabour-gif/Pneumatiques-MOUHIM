<?php

namespace App\Services\Backups;

use App\Enums\RoleName;
use App\Models\User;
use Filament\Notifications\Notification;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Tells the owners when a scheduled backup task fails (audit 16/11/2026: the daily backup
 * failed every night and nobody was told).
 *
 * Sent immediately (not queued) to the in-app notifications, and to the owners' phones when
 * Web Push is configured; spatie's own e-mail notification still applies when mail is set up.
 */
class BackupAlerts
{
    /** @param  string  $task  backup:run, backup:clean or backup:monitor */
    public function taskFailed(string $task): void
    {
        Log::error("Scheduled backup task [{$task}] failed: the owners are notified.");

        try {
            $owners = User::role(RoleName::Owner->value)->where('is_active', true)->get();

            foreach ($owners as $owner) {
                $locale = $owner->locale?->value;
                $owner->notifyNow(Notification::make()->danger()
                    ->title(__('backups.alert.title', [], $locale))
                    ->body(__('backups.alert.body', ['task' => __("backups.tasks.{$task}", [], $locale)], $locale))
                    ->toDatabase());
            }
        } catch (Throwable $e) {
            // The alert must never break the scheduler; the log line above remains.
            report($e);
        }
    }
}
