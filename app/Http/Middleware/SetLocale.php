<?php

namespace App\Http\Middleware;

use App\Enums\Locale;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\App;
use Symfony\Component\HttpFoundation\Response;

/**
 * Locale priority: logged-in user's preference > session (login page) > default.
 */
class SetLocale
{
    public function handle(Request $request, Closure $next): Response
    {
        $locale = $request->user()?->locale?->value
            ?? Locale::tryFrom((string) $request->session()->get('locale'))?->value
            ?? config('mouhim.default_locale');

        App::setLocale($locale);

        return $next($request);
    }
}
