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
                ? Storage::disk('public')->path($company['company.logo'])
                : (is_file(public_path('brand/logo.png')) ? public_path('brand/logo.png') : null);

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
                'margin_left' => 12, 'margin_right' => 12, 'margin_top' => 42, 'margin_bottom' => 40,
                'margin_header' => 8, 'margin_footer' => 6,
                'default_font' => 'dejavusans', 'autoScriptToLang' => true, 'autoLangToFont' => true,
            ]);
            $mpdf->SetTitle($sale->number ?? 'Brouillon');
            // Company paper (letterhead) on every page: name / logo, yellow corners, brands and legal lines.
            $accent = (string) ($company['documents.accent_color'] ?? '#F5B800');
            $mpdf->SetHTMLHeader(view('pdf.letterhead-header', ['company' => $company, 'logo' => $logo, 'corner' => $this->corner($accent, true)])->render());
            $mpdf->SetHTMLFooter(view('pdf.letterhead-footer', ['company' => $company, 'number' => $sale->number])->render());
            $mpdf->SetWatermarkImage($this->corner($accent, false), 1, [38, 38], [0, 259]);
            $mpdf->showWatermarkImage = true;
            $mpdf->WriteHTML($html);

            return $mpdf->Output('', 'S');
        } finally {
            app()->setLocale($previous);
        }
    }

    /** Yellow "V" stroke of the company paper (top right) or bottom-left corner, as an SVG image. */
    private function corner(string $color, bool $top): string
    {
        $svg = $top
            ? '<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 100 100"><path d="M10 0 L100 0 L100 8 L30 8 L78 70 Q86 80 100 82 L100 100 Q78 98 66 82 Z" fill="'.$color.'"/></svg>'
            : '<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 100 100"><path d="M0 20 Q30 22 46 50 L72 100 L52 100 L30 58 Q18 38 0 36 Z" fill="'.$color.'"/></svg>';

        return 'data:image/svg+xml;base64,'.base64_encode($svg);
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
