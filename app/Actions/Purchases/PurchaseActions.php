<?php

namespace App\Actions\Purchases;

use App\Enums\DocumentType;
use App\Enums\Permission;
use App\Enums\StockMovementType;
use App\Exceptions\BusinessRuleException;
use App\Models\Product;
use App\Models\PurchaseOrder;
use App\Models\PurchaseReceipt;
use App\Models\PurchaseReceiptLine;
use App\Models\Site;
use App\Models\Supplier;
use App\Models\SupplierPayment;
use App\Models\User;
use App\Services\SequenceService;
use App\Services\SettingsService;
use App\Services\Stock\LotInput;
use App\Services\Stock\StockService;
use App\Support\Money;
use Filament\Notifications\Notification;
use Illuminate\Support\Facades\DB;

/**
 * Purchase cycle (§13, phase 6): optional order → receipt (total or
 * partial) at the chosen site → stock in at its cost (DOT for tyres) →
 * supplier debt → payment. A cost rise alerts the owner with the new
 * suggested selling price.
 */
class PurchaseActions
{
    public function __construct(private StockService $stock, private SequenceService $sequences, private SettingsService $settings) {}

    /** @param  list<array{product_id: int, quantity: int, unit_cost_cents: int}>  $lines */
    public function order(Supplier $supplier, Site $site, array $lines, User $user, ?string $expectedAt = null, ?string $notes = null): PurchaseOrder
    {
        $this->authorize($user, $site);
        $lines = array_values(array_filter($lines, fn ($l) => (int) ($l['quantity'] ?? 0) > 0));
        if ($lines === []) {
            throw BusinessRuleException::make('purchases.errors.no_lines');
        }

        return DB::transaction(function () use ($supplier, $site, $lines, $user, $expectedAt, $notes) {
            $order = PurchaseOrder::query()->create([
                'number' => $this->sequences->nextDocumentNumber(DocumentType::PurchaseOrder),
                'supplier_id' => $supplier->id, 'site_id' => $site->id, 'status' => 'ordered',
                'expected_at' => $expectedAt, 'notes' => $notes, 'created_by' => $user->id,
            ]);
            foreach ($lines as $l) {
                $product = Product::query()->findOrFail($l['product_id']);
                if (! $product->isStocked()) {
                    throw BusinessRuleException::make('stock.errors.not_stocked', ['product' => $product->name]);
                }
                $order->lines()->create(['product_id' => $product->id, 'quantity_ordered' => (int) $l['quantity'], 'unit_cost_cents' => max(0, (int) $l['unit_cost_cents'])]);
            }

            return $order->load('lines');
        });
    }

    /**
     * @param  list<array{product_id: int, quantity: int, unit_cost_cents: int, dot_week?: ?int, dot_year?: ?int, order_line_id?: ?int}>  $lines
     */
    public function receive(Supplier $supplier, Site $site, array $lines, User $user, ?string $invoiceRef = null, ?PurchaseOrder $order = null): PurchaseReceipt
    {
        $this->authorize($user, $site);
        $lines = array_values(array_filter($lines, fn ($l) => (int) ($l['quantity'] ?? 0) > 0));
        if ($lines === []) {
            throw BusinessRuleException::make('purchases.errors.no_lines');
        }

        return DB::transaction(function () use ($supplier, $site, $lines, $user, $invoiceRef, $order) {
            if ($order) {
                $order = PurchaseOrder::query()->with('lines')->lockForUpdate()->findOrFail($order->id);
                if (in_array($order->status, ['received', 'cancelled'], true) || $order->supplier_id !== $supplier->id) {
                    throw BusinessRuleException::make('purchases.errors.order_closed');
                }
            }

            $vat = $this->settings->vatRateBasisPoints();
            $receipt = PurchaseReceipt::query()->create([
                'purchase_order_id' => $order?->id, 'supplier_id' => $supplier->id, 'site_id' => $site->id, 'invoice_ref' => $invoiceRef,
                'total_ht_cents' => 0, 'vat_rate_bp' => $vat, 'total_ttc_cents' => 0, 'user_id' => $user->id, 'received_at' => now(),
            ]);

            $totalHt = 0;
            foreach ($lines as $l) {
                $product = Product::query()->findOrFail($l['product_id']);
                $qty = (int) $l['quantity'];
                $cost = max(0, (int) $l['unit_cost_cents']);
                $week = filled($l['dot_week'] ?? null) ? (int) $l['dot_week'] : null;
                if ($week !== null && ($week < 1 || $week > 53)) {
                    throw BusinessRuleException::make('stock.errors.invalid_dot');
                }
                $previous = $this->lastCost($product);

                $this->stock->receive($product, $site, $qty, $cost, StockMovementType::PurchaseReceipt, $user, $supplier->name, $receipt,
                    new LotInput($week, filled($l['dot_year'] ?? null) ? (int) $l['dot_year'] : null, $supplier->name));
                PurchaseReceiptLine::query()->create(['purchase_receipt_id' => $receipt->id, 'product_id' => $product->id, 'quantity' => $qty,
                    'unit_cost_cents' => $cost, 'dot_week' => $week, 'dot_year' => $l['dot_year'] ?? null]);
                $totalHt += $qty * $cost;

                if ($order && ($line = $order->lines->firstWhere('id', (int) ($l['order_line_id'] ?? 0)) ?? $order->lines->firstWhere('product_id', $product->id))) {
                    $line->increment('quantity_received', $qty);
                }
                $this->alertCostRise($product->refresh(), $previous, $cost);
            }

            $receipt->update(['total_ht_cents' => $totalHt, 'total_ttc_cents' => intdiv($totalHt * (10000 + $vat) + 5000, 10000)]);

            if ($order) {
                $order->load('lines');
                $order->update(['status' => $order->lines->every(fn ($l) => $l->quantity_received >= $l->quantity_ordered) ? 'received' : 'partial']);
            }

            activity('purchases')->causedBy($user)->performedOn($receipt)->event('purchase_received')
                ->withProperties(['supplier' => $supplier->name, 'total_ht_cents' => $totalHt, 'order' => $order?->number])
                ->tap(fn ($a) => $a->site_id = $site->id)->log('purchase_received');

            return $receipt->load('lines');
        });
    }

    public function pay(Supplier $supplier, int $amountCents, string $method, ?string $reference, User $user): SupplierPayment
    {
        if (! $user->can(Permission::SuppliersPay->value)) {
            throw BusinessRuleException::make('app.errors.forbidden');
        }
        if ($amountCents <= 0) {
            throw BusinessRuleException::make('money.errors.amount');
        }

        $payment = $supplier->payments()->create(['amount_cents' => $amountCents, 'method' => $method, 'reference' => $reference,
            'paid_at' => today(), 'user_id' => $user->id]);
        activity('purchases')->causedBy($user)->performedOn($payment)->event('supplier_paid')
            ->withProperties(['supplier' => $supplier->name, 'amount_cents' => $amountCents])->log('supplier_paid');

        return $payment;
    }

    /** Last purchase cost of a product, any supplier (price comparison). */
    public function lastCost(Product $product): ?int
    {
        return PurchaseReceiptLine::query()->where('product_id', $product->id)->latest('id')->value('unit_cost_cents');
    }

    /** Last cost per supplier, cheapest first (§13 « comparaison des prix »). */
    public function compare(Product $product): array
    {
        return PurchaseReceiptLine::query()->join('purchase_receipts', 'purchase_receipts.id', '=', 'purchase_receipt_lines.purchase_receipt_id')
            ->join('suppliers', 'suppliers.id', '=', 'purchase_receipts.supplier_id')
            ->where('purchase_receipt_lines.product_id', $product->id)
            ->orderByDesc('purchase_receipt_lines.id')->get(['suppliers.name', 'purchase_receipt_lines.unit_cost_cents', 'purchase_receipts.received_at'])
            ->unique('name')->sortBy('unit_cost_cents')->values()->all();
    }

    private function alertCostRise(Product $product, ?int $previous, int $cost): void
    {
        if ($previous === null || $previous === 0 || $cost * 10000 <= $previous * (10000 + (int) $this->settings->get('purchases.cost_alert_bp'))) {
            return;
        }
        $owners = User::query()->where('is_active', true)->get()->filter(fn (User $u) => $u->can(Permission::ApprovalsDecide->value));
        foreach ($owners as $owner) {
            Notification::make()->warning()
                ->title(__('purchases.cost_rise', ['product' => $product->name, 'from' => Money::format($previous), 'to' => Money::format($cost)]))
                ->body(($s = $product->suggestedPriceCents()) ? __('purchases.suggested_price', ['price' => Money::format($s)]) : null)
                ->sendToDatabase($owner);
        }
    }

    private function authorize(User $user, Site $site): void
    {
        if (! $user->can(Permission::PurchasesManage->value) || ! $user->canAccessSite($site)) {
            throw BusinessRuleException::make('app.errors.forbidden');
        }
    }
}
