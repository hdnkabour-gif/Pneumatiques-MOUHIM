<?php

namespace App\Http\Controllers;

use App\Enums\Locale;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;

/** Stores the chosen language on the user (or in the session before login). */
class SwitchLocaleController
{
    public function __invoke(Request $request, string $locale): RedirectResponse
    {
        $locale = Locale::tryFrom($locale) ?? abort(404);

        $request->session()->put('locale', $locale->value);
        $request->user()?->update(['locale' => $locale]);

        return redirect()->back(fallback: '/admin');
    }
}
