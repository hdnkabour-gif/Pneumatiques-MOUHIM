<?php

namespace App\Http\Controllers;

use App\Models\Transfer;
use App\Models\UsedTyre;
use App\Services\SettingsService;
use chillerlan\QRCode\Output\QROutputInterface;
use chillerlan\QRCode\QRCode;
use chillerlan\QRCode\QROptions;
use Illuminate\Support\Facades\Gate;
use Illuminate\View\View;

/** Printable pages (browser print, A4 or label). Every page checks the policy. */
class PrintController
{
    public function transfer(Transfer $transfer, SettingsService $settings): View
    {
        abort_unless(auth()->user()->is_active, 403);
        Gate::authorize('view', $transfer);
        app()->setLocale('fr'); // commercial documents in French by default (§5)

        return view('print.transfer', [
            'transfer' => $transfer->load(['lines.product.tyreSpec', 'fromSite', 'toSite', 'sender', 'receiver']),
            'company' => $settings->all(),
        ]);
    }

    public function usedTyreLabel(UsedTyre $usedTyre): View
    {
        abort_unless(auth()->user()->is_active, 403);
        Gate::authorize('view', $usedTyre);

        $url = route('filament.admin.resources.used-tyres.view', $usedTyre);
        $svg = (new QRCode(new QROptions([
            'outputType' => QROutputInterface::MARKUP_SVG,
            'outputBase64' => false,
            'addQuietzone' => false,
        ])))->render($url);

        return view('print.used-tyre-label', ['tyre' => $usedTyre->load('brand', 'site'), 'qr' => $svg]);
    }
}
