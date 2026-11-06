<?php

namespace App\Actions\Sales;

use App\Enums\Permission;
use App\Enums\QuoteStatus;
use App\Enums\SaleKind;
use App\Enums\SaleStatus;
use App\Exceptions\BusinessRuleException;
use App\Models\Sale;
use App\Models\User;
use App\Services\Stock\StockService;
use Illuminate\Support\Facades\DB;

/** Quote follow-up (§10.3): convert into a sale in one click, refuse, expire. */
class QuoteActions
{
    public function __construct(private StockService $stock) {}

    /**
     * Prepares the sale: a draft with the quote's lines and prices (never
     * above today's catalogue price) and its approvals, opened on the sale
     * screen where the seller takes the payment.
     */
    public function toDraft(Sale $quote, User $user): Sale
    {
        $this->authorize($quote, $user);

        if ($quote->quote_status !== QuoteStatus::Sent) {
            throw BusinessRuleException::make('sales.errors.quote_not_open');
        }

        return DB::transaction(function () use ($quote, $user) {
            $existing = Sale::query()->where('quote_id', $quote->id)->where('status', SaleStatus::Draft->value)->first();
            if ($existing) {
                return $existing;
            }

            $draft = Sale::query()->create([
                'kind' => SaleKind::Invoice,
                'status' => SaleStatus::Draft,
                'quote_id' => $quote->id,
                'seller_id' => $user->id,
                ...$quote->only(['site_id', 'customer_id', 'customer_vehicle_id', 'mileage', 'price_level', 'po_number', 'notes',
                    'total_ht_cents', 'total_vat_cents', 'total_ttc_cents', 'discount_cents', 'approvals']),
            ]);
            $draft->lines()->createMany($quote->lines->map(fn ($l) => collect($l->getAttributes())
                ->except(['id', 'sale_id', 'unit_cost_cents', 'meta', 'restock', 'original_line_id'])->all())->all());

            return $draft;
        });
    }

    public function refuse(Sale $quote, User $user): void
    {
        $this->authorize($quote, $user);
        $this->close($quote, QuoteStatus::Refused);
    }

    /** Daily: quotes past their validity are expired and their stock released. */
    public function expireOld(): int
    {
        $count = 0;
        Sale::query()->where('kind', SaleKind::Quote->value)->where('quote_status', QuoteStatus::Sent->value)
            ->whereDate('valid_until', '<', today())->each(function (Sale $quote) use (&$count) {
                $this->close($quote, QuoteStatus::Expired);
                $count++;
            });

        return $count;
    }

    public function releaseReservation(Sale $quote): void
    {
        if (! $quote->reserved) {
            return;
        }

        foreach ($quote->lines()->with('product')->where('kind', 'goods')->get() as $line) {
            $this->stock->release($line->product, $quote->site, $line->quantity);
        }
        $quote->forceFill(['reserved' => false])->save();
    }

    private function close(Sale $quote, QuoteStatus $status): void
    {
        DB::transaction(function () use ($quote, $status) {
            $quote = Sale::query()->lockForUpdate()->findOrFail($quote->id);
            if ($quote->quote_status !== QuoteStatus::Sent) {
                throw BusinessRuleException::make('sales.errors.quote_not_open');
            }
            $this->releaseReservation($quote);
            $quote->forceFill(['quote_status' => $status])->save();
            activity('sales')->performedOn($quote)->event('quote_'.$status->value)
                ->tap(fn ($a) => $a->site_id = $quote->site_id)->log('quote_'.$status->value);
        });
    }

    private function authorize(Sale $quote, User $user): void
    {
        if (! $quote->isKind(SaleKind::Quote) || ! $user->can(Permission::SalesCreate->value) || ! $user->canAccessSite($quote->site_id)) {
            throw BusinessRuleException::make('app.errors.forbidden');
        }
    }
}
