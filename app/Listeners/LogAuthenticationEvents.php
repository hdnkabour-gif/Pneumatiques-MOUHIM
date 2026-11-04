<?php

namespace App\Listeners;

use App\Models\User;
use Illuminate\Auth\Events\Failed;
use Illuminate\Auth\Events\Login;
use Illuminate\Auth\Events\Logout;
use Illuminate\Events\Dispatcher;

/**
 * Records logins, logouts and failed attempts in the activity log.
 * Methods are not named handle*() so automatic event discovery does not
 * register them a second time next to subscribe().
 */
class LogAuthenticationEvents
{
    public function onLogin(Login $event): void
    {
        if (! $event->user instanceof User) {
            return;
        }

        $event->user->forceFill(['last_login_at' => now()])->saveQuietly();

        activity('auth')->causedBy($event->user)->performedOn($event->user)
            ->event('login')->withProperties(['ip' => request()->ip()])->log('login');
    }

    public function onLogout(Logout $event): void
    {
        if (! $event->user instanceof User) {
            return;
        }

        activity('auth')->causedBy($event->user)->performedOn($event->user)
            ->event('logout')->withProperties(['ip' => request()->ip()])->log('logout');
    }

    public function onFailed(Failed $event): void
    {
        activity('auth')
            ->event('login_failed')
            ->withProperties([
                'email' => $event->credentials['email'] ?? null,
                'ip' => request()->ip(),
            ])
            ->log('login_failed');
    }

    /** @return array<class-string, string> */
    public function subscribe(Dispatcher $events): array
    {
        return [
            Login::class => 'onLogin',
            Logout::class => 'onLogout',
            Failed::class => 'onFailed',
        ];
    }
}
