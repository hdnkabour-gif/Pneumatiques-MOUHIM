<?php

namespace App\Services\Sales;

use App\Enums\SaleKind;
use App\Models\Sale;
use App\Services\SettingsService;
use App\Support\Money;
use App\Support\Normalize;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\URL;
use Mpdf\Mpdf;

/** A4 invoice PDF (D-51, mPDF for Arabic support) and WhatsApp sharing (D-26). */
class SaleDocuments
{
    public function __construct(private SettingsService $settings) {}

    public function pdf(Sale $sale): string
    {
        $previous = app()->getLocale();
        app()->setLocale('fr'); // commercial documents in French by default (§5)

        try {
            $sale->loadMissing(['lines', 'customer', 'vehicle.vehicleModel.make', 'site', 'seller', 'payments', 'originalSale']);
            $company = $this->settings->all();
            $logo = filled($company['company.logo'] ?? null) && Storage::disk('public')->exists($company['company.logo'])
                ? Storage::disk('public')->path($company['company.logo']) : null;

            $html = view('pdf.invoice', [
                'sale' => $sale,
                'company' => $company,
                'logo' => $logo,
                'vat' => $sale->lines->groupBy('vat_rate_bp')->map(fn ($lines, $rate) => [
                    'rate' => $rate, 'ht' => $lines->sum('total_ht_cents'), 'vat' => $lines->sum('total_vat_cents'),
                ])->sortKeys()->values(),
            ])->render();

            $dir = storage_path('app/mpdf');
            if (! is_dir($dir)) {
                mkdir($dir, 0775, true);
            }

            $mpdf = new Mpdf([
                'mode' => 'utf-8', 'format' => 'A4', 'tempDir' => $dir,
                'margin_left' => 12, 'margin_right' => 12, 'margin_top' => 12, 'margin_bottom' => 18,
                'default_font' => 'dejavusans', 'autoScriptToLang' => true, 'autoLangToFont' => true,
            ]);
            $mpdf->SetTitle($sale->number ?? 'Brouillon');
            $mpdf->SetFooter($sale->number.' – {PAGENO}/{nbpg}');
            $mpdf->WriteHTML($html);

            return $mpdf->Output('', 'S');
        } finally {
            app()->setLocale($previous);
        }
    }

    /** Link valid 30 days, readable without an account (sent to the customer). */
    public function publicUrl(Sale $sale): string
    {
        return URL::temporarySignedRoute('sales.public-pdf', now()->addDays(30), ['sale' => $sale->id]);
    }

    public function whatsappUrl(Sale $sale): ?string
    {
        if ($sale->isDraft() || blank($sale->customer?->phone)) {
            return null;
        }

        $message = __('sales.whatsapp.'.($sale->isKind(SaleKind::Quote) ? 'quote' : 'invoice'), [
            'name' => $sale->customer->name,
            'company' => $this->settings->get('company.name'),
            'number' => $sale->number,
            'total' => Money::format($sale->total_ttc_cents),
            'url' => $this->publicUrl($sale),
            'until' => $sale->valid_until?->format('d/m/Y'),
            'document' => mb_strtolower($sale->documentType()->label()),
        ], 'fr');

        return Normalize::whatsappUrl($sale->customer->phone, $message);
    }
}
