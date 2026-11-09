<?php

namespace App\Services\Reports;

use App\Enums\ApprovalStatus;
use App\Enums\PaymentMethod;
use App\Enums\SaleKind;
use App\Enums\SaleStatus;
use App\Models\ApprovalRequest;
use App\Models\CashDay;
use App\Models\Expense;
use App\Models\Payment;
use App\Models\Sale;
use App\Models\Site;
use App\Support\Money;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;

/**
 * Figures of the « Aujourd'hui » page and of the evening summary (§16):
 * one place, so the phone and the summary always say the same thing.
 */
class DailyFigures
{
    /** @return array{sales: int, count: int, cash: int, card: int, cheque: int, credit: int, expenses: int, margin: ?int, credit_notes: int} */
    public function forDay(?Site $site, CarbonImmutable $day): array
    {
        [$from, $to] = [$day->startOfDay()->utc(), $day->endOfDay()->utc()];
        $sales = Sale::query()->with('lines')->where('status', SaleStatus::Confirmed->value)
            ->whereIn('kind', [SaleKind::Invoice->value, SaleKind::DeliveryNote->value])
            // A recap invoice repeats notes already counted on their own day.
            ->whereNotIn('id', Sale::query()->whereNotNull('recap_invoice_id')->select('recap_invoice_id'))
            ->whereBetween('confirmed_at', [$from, $to])->when($site, fn ($q) => $q->where('site_id', $site->id))->get();
        $payments = Payment::query()->whereBetween('received_at', [$from, $to])->where('status', '!=', 'rejected')
            ->when($site, fn ($q) => $q->where('site_id', $site->id))->get()->groupBy(fn ($p) => $p->method->value);
        $creditNotes = (int) Sale::query()->where('kind', SaleKind::CreditNote->value)->where('status', SaleStatus::Confirmed->value)
            ->whereBetween('confirmed_at', [$from, $to])->when($site, fn ($q) => $q->where('site_id', $site->id))->sum('total_ttc_cents');

        $margins = $sales->map(fn (Sale $s) => $s->marginCents());

        return [
            'sales' => (int) $sales->sum('total_ttc_cents'),
            'count' => $sales->count(),
            'cash' => (int) ($payments[PaymentMethod::Cash->value] ?? collect())->sum('amount_cents'),
            'card' => (int) ($payments[PaymentMethod::Card->value] ?? collect())->sum('amount_cents'),
            'cheque' => (int) ($payments[PaymentMethod::Cheque->value] ?? collect())->sum('amount_cents'),
            'credit' => (int) $sales->sum('credit_cents'),
            'expenses' => (int) Expense::query()->where('business_date', $day->toDateString())->when($site, fn ($q) => $q->where('site_id', $site->id))->sum('amount_cents'),
            // Never guessed: null as soon as one sale has an unknown cost (CLAUDE.md §4).
            'margin' => $margins->contains(null) ? null : (int) $margins->sum(),
            'credit_notes' => $creditNotes,
        ];
    }

    /** Sales of the month so far (TTC), for the target bar. */
    public function monthSales(Site $site, CarbonImmutable $day): int
    {
        return (int) Sale::query()->where('site_id', $site->id)->where('status', SaleStatus::Confirmed->value)
            ->whereIn('kind', [SaleKind::Invoice->value, SaleKind::DeliveryNote->value])
            ->whereNotIn('id', Sale::query()->whereNotNull('recap_invoice_id')->select('recap_invoice_id'))
            ->whereBetween('confirmed_at', [$day->startOfMonth()->utc(), $day->endOfDay()->utc()])->sum('total_ttc_cents');
    }

    public function target(Site $site, CarbonImmutable $day): ?int
    {
        return DB::table('site_objectives')->where('site_id', $site->id)->where('month', $day->format('Y-m'))->value('target_cents');
    }

    /** At most five things to deal with now (§16.1). */
    public function alerts(CarbonImmutable $day): array
    {
        $alerts = [];
        if ($n = ApprovalRequest::query()->where('status', ApprovalStatus::Pending->value)->count()) {
            $alerts[] = __('today.alerts.approvals', ['count' => $n]);
        }
        $cheques = Payment::query()->where('method', PaymentMethod::Cheque->value)->where('status', 'received')
            ->whereDate('due_date', '<=', $day->toDateString())->get();
        if ($cheques->isNotEmpty()) {
            $alerts[] = __('today.alerts.cheques', ['count' => $cheques->count(), 'amount' => Money::format((int) $cheques->sum('amount_cents'))]);
        }
        foreach (CashDay::query()->with('site')->where('business_date', $day->toDateString())->whereNotNull('closed_at')->where('difference_cents', '!=', 0)->get() as $d) {
            $alerts[] = __('cash.discrepancy', ['site' => $d->site->code, 'amount' => Money::format($d->difference_cents)]);
        }
        $late = Sale::query()->where('status', SaleStatus::Confirmed->value)->whereIn('kind', [SaleKind::Invoice->value, SaleKind::DeliveryNote->value])
            ->whereNull('recap_invoice_id')->where('credit_cents', '>', 0)->where('confirmed_at', '<', $day->subDays(30)->utc())->distinct()->count('customer_id');
        if ($late) {
            $alerts[] = __('today.alerts.late', ['count' => $late]);
        }

        return array_slice($alerts, 0, 5);
    }
}
