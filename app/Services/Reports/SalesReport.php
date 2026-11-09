<?php

namespace App\Services\Reports;

use App\Enums\SaleKind;
use App\Enums\SaleStatus;
use App\Models\Sale;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * Reports (§16.3) over a period and the sites a user may see. Sales are
 * invoices and delivery notes (a recap invoice is not counted twice);
 * margins are never guessed: a group with an unknown cost is "incomplete".
 */
class SalesReport
{
    public function __construct(private CarbonImmutable $from, private CarbonImmutable $to, private ?int $siteId, private User $user) {}

    private function lines(): Builder
    {
        return DB::table('sale_lines as l')->join('sales as s', 's.id', '=', 'l.sale_id')
            ->where('s.status', SaleStatus::Confirmed->value)->whereIn('s.kind', [SaleKind::Invoice->value, SaleKind::DeliveryNote->value])
            ->whereNotIn('s.id', DB::table('sales')->whereNotNull('recap_invoice_id')->select('recap_invoice_id'))
            ->whereBetween('s.confirmed_at', [$this->from->startOfDay()->utc(), $this->to->endOfDay()->utc()])
            ->when($this->siteId, fn ($q) => $q->where('s.site_id', $this->siteId))
            ->when(! $this->user->canSeeAllSites(), fn ($q) => $q->whereIn('s.site_id', $this->user->siteIds()));
    }

    private const AGG = 'SUM(l.total_ttc_cents) AS ttc, SUM(l.total_ht_cents) AS ht, COUNT(DISTINCT s.id) AS docs,
        SUM(CASE WHEN l.kind = \'trade_in\' THEN 0 ELSE l.total_ht_cents - COALESCE(l.unit_cost_cents, 0) * l.quantity END) AS margin,
        BOOL_OR(l.unit_cost_cents IS NULL AND l.kind <> \'trade_in\') AS incomplete';

    public function summary(): object
    {
        $r = $this->lines()->selectRaw(self::AGG)->first();
        $r->basket = $r->docs ? intdiv((int) $r->ttc, (int) $r->docs) : 0;

        return $r;
    }

    /** @param  'site'|'seller'|'category'|'brand'  $by */
    public function by(string $by): Collection
    {
        $q = $this->lines();
        match ($by) {
            'site' => $q->join('sites as g', 'g.id', '=', 's.site_id'),
            'seller' => $q->leftJoin('users as g', 'g.id', '=', 's.seller_id'),
            'category' => $q->leftJoin('products as p', 'p.id', '=', 'l.product_id')->leftJoin('categories as g', 'g.id', '=', 'p.category_id'),
            'brand' => $q->leftJoin('products as p', 'p.id', '=', 'l.product_id')->leftJoin('brands as g', 'g.id', '=', 'p.brand_id'),
        };

        return $q->selectRaw("COALESCE(g.name, '—') AS label, ".self::AGG)->groupBy('g.name')->orderByDesc('ttc')->get();
    }

    public function topProducts(int $limit = 10): Collection
    {
        return $this->lines()->whereNotNull('l.product_id')->whereNull('l.bundle_id')
            ->selectRaw('l.description AS label, SUM(l.quantity) AS qty, '.self::AGG)
            ->groupBy('l.description')->orderByDesc('ttc')->limit($limit)->get();
    }

    /** Debts by age of the unpaid document: 0–30, 31–60, 61–90, > 90 days (balance âgée). */
    public function agedBalance(): Collection
    {
        $now = CarbonImmutable::now();
        $docs = Sale::query()->with('customer')->where('status', SaleStatus::Confirmed->value)
            ->whereIn('kind', [SaleKind::Invoice->value, SaleKind::DeliveryNote->value])->whereNull('recap_invoice_id')
            ->where('credit_cents', '>', 0)->whereNotNull('customer_id')
            ->when($this->siteId, fn ($q) => $q->where('site_id', $this->siteId))->visibleTo($this->user)->get();

        return $docs->groupBy('customer_id')->map(function (Collection $sales) use ($now) {
            $row = ['customer' => $sales->first()->customer->name, 'b0' => 0, 'b30' => 0, 'b60' => 0, 'b90' => 0];
            foreach ($sales as $s) {
                $days = (int) $s->confirmed_at->diffInDays($now);
                $row[$days <= 30 ? 'b0' : ($days <= 60 ? 'b30' : ($days <= 90 ? 'b60' : 'b90'))] += $s->credit_cents;
            }
            $row['total'] = $row['b0'] + $row['b30'] + $row['b60'] + $row['b90'];

            return (object) $row;
        })->sortByDesc('total')->values();
    }

    /** Stock value at average cost per site; unknown costs are counted apart. */
    public function stockValue(): Collection
    {
        return DB::table('stock_levels as sl')->join('products as p', 'p.id', '=', 'sl.product_id')->join('sites as g', 'g.id', '=', 'sl.site_id')
            ->where('sl.quantity', '>', 0)
            ->when($this->siteId, fn ($q) => $q->where('sl.site_id', $this->siteId))
            ->when(! $this->user->canSeeAllSites(), fn ($q) => $q->whereIn('sl.site_id', $this->user->siteIds()))
            ->selectRaw('g.name AS label, SUM(sl.quantity) AS qty, SUM(sl.quantity * COALESCE(p.average_cost_cents, 0)) AS value,
                SUM(CASE WHEN p.average_cost_cents IS NULL THEN sl.quantity ELSE 0 END) AS unknown')
            ->groupBy('g.name')->orderBy('g.name')->get();
    }
}
