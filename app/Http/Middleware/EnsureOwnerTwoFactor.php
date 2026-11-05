<?php

namespace App\Http\Middleware;

use App\Models\User;
use Closure;
use Filament\Facades\Filament;
use Filament\Notifications\Notification;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * An owner without two-factor authentication is sent to his profile page to
 * set it up before using anything else (§19, D-47).
 */
class EnsureOwnerTwoFactor
{
    public function handle(Request $request, Closure $next): Response
    {
        $user = $request->user();
        $profileUrl = Filament::getProfileUrl();

        if ($user instanceof User && $user->requiresTwoFactor()
            && ! User::query()->whereKey($user->getKey())->whereNotNull('app_authentication_secret')->exists()
            && $profileUrl && $request->url() !== $profileUrl) {
            Notification::make()->warning()->duration(15000)
                ->title(__('app.two_factor.required_title'))
                ->body(__('app.two_factor.required_body'))
                ->send();

            return redirect($profileUrl);
        }

        return $next($request);
    }
}
