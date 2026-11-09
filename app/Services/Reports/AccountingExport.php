<?php

namespace App\Services\Reports;

use App\Enums\SaleKind;
use App\Enums\SaleStatus;
use App\Models\Expense;
use App\Models\Payment;
use App\Models\PurchaseReceipt;
use App\Models\Sale;
use Carbon\CarbonImmutable;
use OpenSpout\Common\Entity\Row;
use OpenSpout\Writer\XLSX\Writer;

/**
 * Accountant export (§16.3): one workbook per month — sales journal with
 * VAT, credit notes, purchases, money received, expenses. Amounts in MAD
 * with 2 decimals; documents are never changed by an export.
 */
class AccountingExport
{
    public function write(CarbonImmutable $month, string $path): void
    {
        [$from, $to] = [$month->startOfMonth()->utc(), $month->endOfMonth()->utc()];
        $mad = fn (?int $cents) => $cents === null ? null : round($cents / 100, 2);
        $writer = new Writer;
        $writer->openToFile($path);

        $first = true;
        $sheet = function (string $name, array $header, iterable $rows) use ($writer, &$first) {
            if (! $first) {
                $writer->addNewSheetAndMakeItCurrent();
            }
            $first = false;
            $writer->getCurrentSheet()->setName($name);
            $writer->addRow(Row::fromValues($header));
            foreach ($rows as $r) {
                $writer->addRow(Row::fromValues($r));
            }
        };

        $sales = Sale::query()->with(['customer', 'site'])->where('status', SaleStatus::Confirmed->value)
            ->whereIn('kind', [SaleKind::Invoice->value, SaleKind::CreditNote->value])->whereBetween('confirmed_at', [$from, $to])
            ->orderBy('confirmed_at')->orderBy('id')->get();
        $sheet('Ventes', ['Date', 'N°', 'Type', 'Site', 'Client', 'ICE client', 'HT', 'TVA', 'TTC', 'Reste à crédit'],
            $sales->map(fn (Sale $s) => [$s->confirmed_at->timezone(config('mouhim.timezone'))->format('d/m/Y'), $s->number, $s->kind->label(),
                $s->site->code, $s->customer?->name, $s->customer?->ice,
                ...array_map(fn ($v) => $s->isKind(SaleKind::CreditNote) ? -$mad($v) : $mad($v), [$s->total_ht_cents, $s->total_vat_cents, $s->total_ttc_cents]),
                $s->isKind(SaleKind::CreditNote) ? 0 : $mad($s->credit_cents)]));

        $vat = [];
        foreach ($sales as $s) {
            $sign = $s->isKind(SaleKind::CreditNote) ? -1 : 1;
            foreach ($s->lines()->get() as $l) {
                $vat[$l->vat_rate_bp] ??= [0, 0];
                $vat[$l->vat_rate_bp][0] += $sign * $l->total_ht_cents;
                $vat[$l->vat_rate_bp][1] += $sign * $l->total_vat_cents;
            }
        }
        ksort($vat);
        $sheet('TVA collectée', ['Taux %', 'Base HT', 'TVA'], array_map(fn ($rate, $v) => [$rate / 100, $mad($v[0]), $mad($v[1])], array_keys($vat), $vat));

        $sheet('Achats', ['Date', 'Fournisseur', 'ICE', 'N° facture', 'Site', 'HT', 'Taux TVA %', 'TTC'],
            PurchaseReceipt::query()->with(['supplier', 'site'])->whereBetween('received_at', [$from, $to])->orderBy('received_at')->get()
                ->map(fn ($r) => [$r->received_at->timezone(config('mouhim.timezone'))->format('d/m/Y'), $r->supplier->name, $r->supplier->ice,
                    $r->invoice_ref, $r->site->code, $mad($r->total_ht_cents), $r->vat_rate_bp / 100, $mad($r->total_ttc_cents)]));

        $sheet('Encaissements', ['Date', 'Client', 'Moyen', 'Montant', 'N° chèque', 'Banque', 'État', 'Site'],
            Payment::query()->with(['customer', 'site'])->whereBetween('received_at', [$from, $to])->orderBy('received_at')->get()
                ->map(fn ($p) => [$p->received_at->timezone(config('mouhim.timezone'))->format('d/m/Y'), $p->customer?->name, $p->method->label(),
                    $mad($p->amount_cents), $p->cheque_number, $p->bank, __('money.cheque_status.'.$p->status), $p->site->code]));

        $sheet('Dépenses', ['Date', 'Site', 'Type', 'Description', 'Montant', 'Payée en caisse'],
            Expense::query()->with('site')->whereBetween('business_date', [$month->startOfMonth()->toDateString(), $month->endOfMonth()->toDateString()])
                ->orderBy('business_date')->get()
                ->map(fn ($e) => [$e->business_date->format('d/m/Y'), $e->site->code, __('cash.categories.'.$e->category), $e->description,
                    $mad($e->amount_cents), $e->paid_in_cash ? 'Oui' : 'Non']));

        $writer->close();
    }
}
