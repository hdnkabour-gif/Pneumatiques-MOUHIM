<?php

namespace App\Actions\Sales;

use App\Enums\DocumentType;
use App\Enums\PaymentMethod;
use App\Enums\Permission;
use App\Enums\SaleStatus;
use App\Enums\StockMovementType;
use App\Enums\UsedTyreStatus;
use App\Enums\WorkOrderStatus;
use App\Exceptions\ApprovalRequiredException;
use App\Exceptions\BusinessRuleException;
use App\Models\Payment;
use App\Models\Product;
use App\Models\Sale;
use App\Models\StockMovement;
use App\Models\UsedTyre;
use App\Models\User;
use App\Models\WorkOrder;
use App\Services\Sales\SaleRules;
use App\Services\SequenceService;
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
    public function execute(Sale $sale, array $payments, User $user, array $technicianIds = []): Sale
    {
        $payments = $this->cleanPayments($payments);

        return DB::transaction(function () use ($sale, $payments, $user, $technicianIds) {
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

            $paid = array_sum(array_column($payments, 'amount_cents'));
            if ($paid > $sale->total_ttc_cents) {
                throw BusinessRuleException::make('sales.errors.overpaid');
            }
            $credit = $sale->total_ttc_cents - $paid;

            if ($credit > 0 && ! $sale->customer) {
                throw BusinessRuleException::make('sales.errors.credit_needs_customer');
            }
            if ($sale->customer?->requires_po_number && blank($sale->po_number)) {
                throw BusinessRuleException::make('sales.errors.po_required');
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

            $this->takeStock($sale, $user);

            foreach ($payments as $p) {
                $payment = Payment::query()->create([
                    ...$p,
                    'site_id' => $sale->site_id,
                    'customer_id' => $sale->customer_id,
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
                'number' => $this->sequences->nextDocumentNumber(DocumentType::Invoice),
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
            ];
        }

        return $clean;
    }
}
