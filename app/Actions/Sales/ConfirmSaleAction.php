<?php

namespace App\Actions\Sales;

use App\Actions\UsedTyres\UsedTyreActions;
use App\Enums\ApprovalType;
use App\Enums\DocumentType;
use App\Enums\OilDrumMovementType;
use App\Enums\PaymentMethod;
use App\Enums\Permission;
use App\Enums\QuoteStatus;
use App\Enums\SaleKind;
use App\Enums\SaleStatus;
use App\Enums\StockMovementType;
use App\Enums\UsedTyreOrigin;
use App\Enums\UsedTyreStatus;
use App\Enums\WorkOrderStatus;
use App\Exceptions\ApprovalRequiredException;
use App\Exceptions\BusinessRuleException;
use App\Models\Customer;
use App\Models\OilDrum;
use App\Models\Payment;
use App\Models\Product;
use App\Models\Sale;
use App\Models\StockMovement;
use App\Models\UsedTyre;
use App\Models\User;
use App\Models\WorkOrder;
use App\Services\Money\CashRegister;
use App\Services\Oil\OilDrumService;
use App\Services\Sales\SaleDocuments;
use App\Services\Sales\SaleRules;
use App\Services\SequenceService;
use App\Services\SettingsService;
use App\Services\Stock\StockService;
use Illuminate\Support\Facades\DB;

/**
 * Validates a draft sale in one transaction (§4, §10.1 step 7): approvals,
 * stock exit (goods, used tyres, consumables of services), invoice number,
 * payments (D-50), remaining credit, work order for services (D-53).
 * Afterwards the invoice is frozen (D-15).
 */
class ConfirmSaleAction
{
    public function __construct(
        private StockService $stock,
        private SequenceService $sequences,
        private SaleRules $rules,
    ) {}

    /**
     * @param  list<array{method: string, amount_cents: int, cheque_number?: ?string, bank?: ?string, drawer?: ?string, due_date?: ?string}>  $payments
     * @param  list<int>  $technicianIds
     */
    public function execute(Sale $sale, array $payments, User $user, array $technicianIds = [], bool $reserve = false): Sale
    {
        $payments = $this->cleanPayments($payments);

        return DB::transaction(function () use ($sale, $payments, $user, $technicianIds, $reserve) {
            $sale = Sale::query()->with(['lines', 'customer', 'site', 'vehicle'])->lockForUpdate()->findOrFail($sale->id);

            if (! $user->can(Permission::SalesCreate->value) || ! $user->canAccessSite($sale->site)) {
                throw BusinessRuleException::make('app.errors.forbidden');
            }
            if (! $sale->isDraft()) {
                throw BusinessRuleException::make('sales.errors.not_draft');
            }
            if ($sale->lines->isEmpty()) {
                throw BusinessRuleException::make('sales.errors.empty');
            }

            if ($sale->kind === SaleKind::Quote) {
                return $this->issueQuote($sale, $user, $reserve);
            }

            // #18 (PM-03): the credit ceiling is a decision about the CUSTOMER, not about
            // this sale. Lock the customer row for the rest of the transaction so two
            // confirmations for the same customer are serialised: the second one reads
            // the outstanding balance only after the first has committed. Same lock order
            // as CustomerAccountActions::receive() (customer, then confirmed sales).
            if ($sale->customer_id) {
                Customer::query()->lockForUpdate()->findOrFail($sale->customer_id);
            }

            $paid = array_sum(array_column($payments, 'amount_cents'));
            if ($paid > $sale->total_ttc_cents) {
                throw BusinessRuleException::make('sales.errors.overpaid');
            }
            $credit = $sale->total_ttc_cents - $paid;
            $voucherTotals = [];
            foreach ($payments as $payment) {
                if ($payment['method'] === PaymentMethod::CreditNote) {
                    $id = $payment['credit_note_id'];
                    $voucherTotals[$id] = ($voucherTotals[$id] ?? 0) + $payment['amount_cents'];
                }
            }
            ksort($voucherTotals);
            foreach ($voucherTotals as $id => $amount) {
                $this->checkVoucher($sale, $id, $amount);
            }

            if ($credit > 0 && ! $sale->customer) {
                throw BusinessRuleException::make('sales.errors.credit_needs_customer');
            }
            if ($sale->customer?->requires_po_number && blank($sale->po_number)) {
                throw BusinessRuleException::make('sales.errors.po_required');
            }

            if (collect($payments)->contains(fn ($p) => $p['method'] === PaymentMethod::Cash)) {
                app(CashRegister::class)->assertOpen($sale->site_id); // D-29
            }

            $missing = $this->rules->required($sale, $user, $credit);
            if ($missing !== [] && $user->can(Permission::ApprovalsDecide->value)) {
                // The owner decides for himself; the decision is still journalled (§4).
                app(RequestSaleApprovalAction::class)->execute($sale, $missing, __('sales.self_approved'), $user, $credit);
                $sale->refresh()->load(['lines', 'customer', 'site', 'vehicle']);
                $missing = $this->rules->required($sale, $user, $credit);
            }
            if ($missing !== []) {
                throw ApprovalRequiredException::for($missing);
            }

            $this->convertQuote($sale);
            $this->takeStock($sale, $user);

            foreach ($payments as $p) {
                $payment = Payment::query()->create([
                    ...$p,
                    'site_id' => $sale->site_id,
                    'customer_id' => $sale->customer_id,
                    'status' => 'received',
                    'received_by' => $user->id,
                    'received_at' => now(),
                ]);
                $payment->sales()->attach($sale->id, ['amount_cents' => $p['amount_cents']]);
            }

            if ($sale->vehicle && $sale->mileage && $sale->mileage > (int) $sale->vehicle->mileage) {
                $sale->vehicle->update(['mileage' => $sale->mileage]);
            }

            // Number last: the counter row stays locked only for the end of the transaction.
            $sale->forceFill([
                'number' => $this->sequences->nextDocumentNumber($sale->documentType()),
                'status' => SaleStatus::Confirmed,
                'paid_cents' => $paid,
                'credit_cents' => $credit,
                'confirmed_by' => $user->id,
                'confirmed_at' => now(),
            ])->save();

            if ($sale->lines->contains(fn ($l) => $l->kind === 'service')) {
                $this->createWorkOrder($sale, $user, $technicianIds);
            }

            activity('sales')->causedBy($user)->performedOn($sale)->event('sale_confirmed')
                ->withProperties([
                    'number' => $sale->number, 'total_ttc_cents' => $sale->total_ttc_cents,
                    'paid_cents' => $paid, 'credit_cents' => $credit, 'approvals' => $sale->approvals,
                ])
                ->tap(fn ($a) => $a->site_id = $sale->site_id)->log('sale_confirmed');

            if ($sale->isKind(SaleKind::Invoice)) {
                app(SaleDocuments::class)->archiveInvoice($sale);
            }

            return $sale->refresh()->load(['lines', 'payments', 'workOrder']);
        });
    }

    private function takeStock(Sale $sale, User $user): void
    {
        $products = Product::query()->with(['consumables.product', 'category'])
            ->whereKey($sale->lines->pluck('product_id')->filter())->get()->keyBy('id');

        foreach ($sale->lines as $line) {
            $cost = null;

            if ($line->kind === 'goods') {
                $product = $products[$line->product_id];
                $this->stock->issue($product, $sale->site, $line->quantity, StockMovementType::Sale, $user, null, $sale);
                $cost = $product->refresh()->average_cost_cents;
            } elseif ($line->kind === 'service') {
                $cost = 0;
                foreach ($products[$line->product_id]->consumables as $consumable) {
                    $qty = $consumable->quantity * $line->quantity;
                    $this->stock->issue($consumable->product, $sale->site, $qty, StockMovementType::WorkshopConsumption, $user, $line->description, $sale);
                    $unit = $consumable->product->refresh()->average_cost_cents;
                    $cost = $cost === null || $unit === null ? null : $cost + $unit * $consumable->quantity;
                }
            } elseif ($line->kind === 'bulk_oil') {
                $drum = OilDrum::query()->findOrFail($line->oil_drum_id);
                $movement = app(OilDrumService::class)->draw($drum, (int) $line->quantity_ml,
                    OilDrumMovementType::Sale, $user, $sale, $sale->site);
                // Cost of the litres frozen on the line: old invoices keep their margin.
                $cost = OilDrumService::costOf($movement->cost_per_litre_cents, (int) $line->quantity_ml);
            } elseif ($line->kind === 'trade_in') {
                // Tyres left by the customer await the safety check before any resale (§5.4).
                if ($line->trade_in_type === 'tyre' && $line->meta) {
                    app(UsedTyreActions::class)->register([...$line->meta, 'cost_cents' => -$line->unit_price_ttc_cents],
                        $sale->site, UsedTyreOrigin::TradeIn, $user, $sale);
                }
                $cost = null;
                $line->forceFill(['unit_cost_cents' => $line->unit_price_ttc_cents])->save();

                continue;
            } else {
                $tyre = UsedTyre::query()->lockForUpdate()->findOrFail($line->used_tyre_id);
                if ($tyre->status !== UsedTyreStatus::InStock || $tyre->site_id !== $sale->site_id) {
                    throw BusinessRuleException::make('sales.errors.used_tyre_unavailable', ['code' => $tyre->code]);
                }
                $tyre->forceFill(['status' => UsedTyreStatus::Sold])->save();
                $movement = new StockMovement([
                    'used_tyre_id' => $tyre->id, 'site_id' => $sale->site_id, 'type' => StockMovementType::Sale,
                    'quantity' => -1, 'unit_cost_cents' => $tyre->cost_cents, 'user_id' => $user->id, 'reason' => $tyre->code,
                ]);
                $movement->document()->associate($sale);
                $movement->save();
                $cost = $tyre->cost_cents;
            }

            // Cost snapshot for the margin; NULL when unknown, never guessed (CLAUDE.md §4).
            $line->forceFill(['unit_cost_cents' => $cost])->save();
        }
    }

    /** D-13 / §10.3: a quote has a number and a validity; it takes no stock nor money. */
    private function issueQuote(Sale $sale, User $user, bool $reserve): Sale
    {
        $missing = array_values(array_filter($this->rules->required($sale, $user, 0), fn ($t) => $t !== ApprovalType::CreditLimit));
        if ($missing !== [] && $user->can(Permission::ApprovalsDecide->value)) {
            app(RequestSaleApprovalAction::class)->execute($sale, $missing, __('sales.self_approved'), $user);
            $sale->refresh()->load(['lines', 'customer', 'site']);
            $missing = array_values(array_filter($this->rules->required($sale, $user, 0), fn ($t) => $t !== ApprovalType::CreditLimit));
        }
        if ($missing !== []) {
            throw ApprovalRequiredException::for($missing);
        }

        // Reserving stock is kept for the owner (decision of 2026-11-05 on D-13).
        $reserve = $reserve && $user->can(Permission::ApprovalsDecide->value);
        if ($reserve) {
            foreach ($sale->lines->where('kind', 'goods') as $line) {
                $this->stock->reserve($line->product, $sale->site, $line->quantity);
            }
        }

        $sale->forceFill([
            'number' => $this->sequences->nextDocumentNumber(DocumentType::Quote),
            'status' => SaleStatus::Confirmed,
            'quote_status' => QuoteStatus::Sent,
            'valid_until' => today()->addDays((int) app(SettingsService::class)->get('sales.quote_validity_days')),
            'reserved' => $reserve,
            'confirmed_by' => $user->id,
            'confirmed_at' => now(),
        ])->save();

        activity('sales')->causedBy($user)->performedOn($sale)->event('quote_issued')
            ->withProperties(['number' => $sale->number, 'total_ttc_cents' => $sale->total_ttc_cents, 'reserved' => $reserve])
            ->tap(fn ($a) => $a->site_id = $sale->site_id)->log('quote_issued');

        return $sale->refresh()->load('lines');
    }

    /** The sale made from a quote frees the quote's reservation before taking the stock. */
    private function convertQuote(Sale $sale): void
    {
        if (! $sale->quote_id) {
            return;
        }

        $quote = Sale::query()->with('lines.product')->lockForUpdate()->find($sale->quote_id);
        if (! $quote || $quote->quote_status !== QuoteStatus::Sent) {
            throw BusinessRuleException::make('sales.errors.quote_not_open');
        }

        app(QuoteActions::class)->releaseReservation($quote);
        $quote->forceFill(['quote_status' => QuoteStatus::Converted])->save();
    }

    /** Exchange voucher (credit note) of the same customer, with enough left on it. */
    private function checkVoucher(Sale $sale, int $id, int $amount): void
    {
        $voucher = Sale::query()->lockForUpdate()->find($id);

        if (! $voucher || $voucher->customer_id !== $sale->customer_id || $voucher->voucherBalanceCents() < $amount) {
            throw BusinessRuleException::make('sales.errors.voucher_invalid');
        }
    }

    /** @param  list<int>  $technicianIds */
    private function createWorkOrder(Sale $sale, User $user, array $technicianIds): void
    {
        $order = WorkOrder::query()->create([
            'number' => $this->sequences->nextDocumentNumber(DocumentType::WorkOrder),
            'site_id' => $sale->site_id,
            'sale_id' => $sale->id,
            'customer_id' => $sale->customer_id,
            'customer_vehicle_id' => $sale->customer_vehicle_id,
            'mileage' => $sale->mileage,
            'status' => WorkOrderStatus::Pending,
            'created_by' => $user->id,
        ]);

        $technicians = User::query()->whereKey($technicianIds)->where('is_active', true)->get()
            ->filter(fn (User $t) => $t->canAccessSite($sale->site_id));
        $order->technicians()->sync($technicians->modelKeys());
    }

    /** @return list<array<string, mixed>> */
    private function cleanPayments(array $payments): array
    {
        $clean = [];
        foreach ($payments as $p) {
            $amount = (int) ($p['amount_cents'] ?? 0);
            if ($amount <= 0) {
                continue;
            }
            $method = PaymentMethod::tryFrom((string) ($p['method'] ?? ''));
            if (! $method) {
                throw BusinessRuleException::make('sales.errors.payment_method');
            }
            if ($method === PaymentMethod::CreditNote && blank($p['credit_note_id'] ?? null)) {
                throw BusinessRuleException::make('sales.errors.voucher_invalid');
            }
            if ($method === PaymentMethod::Cheque && (blank($p['cheque_number'] ?? null) || blank($p['bank'] ?? null))) {
                throw BusinessRuleException::make('sales.errors.cheque_details');
            }
            $clean[] = [
                'method' => $method,
                'amount_cents' => $amount,
                'cheque_number' => $method === PaymentMethod::Cheque ? trim($p['cheque_number']) : null,
                'bank' => $method === PaymentMethod::Cheque ? trim($p['bank']) : null,
                'drawer' => $method === PaymentMethod::Cheque ? ($p['drawer'] ?? null) : null,
                'due_date' => $method === PaymentMethod::Cheque ? ($p['due_date'] ?? null) : null,
                'credit_note_id' => $method === PaymentMethod::CreditNote ? (int) $p['credit_note_id'] : null,
            ];
        }

        return $clean;
    }
}
