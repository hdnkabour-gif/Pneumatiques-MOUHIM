<?php

namespace App\Actions\Sales;

use App\Enums\ApprovalType;
use App\Enums\DocumentType;
use App\Enums\Permission;
use App\Enums\RefundMethod;
use App\Enums\SaleKind;
use App\Enums\SaleStatus;
use App\Enums\StockMovementType;
use App\Enums\UsedTyreStatus;
use App\Exceptions\BusinessRuleException;
use App\Models\ApprovalRequest;
use App\Models\OilDrum;
use App\Models\Sale;
use App\Models\SaleLine;
use App\Models\StockMovement;
use App\Models\UsedTyre;
use App\Models\User;
use App\Services\Approvals\ApprovalService;
use App\Services\Money\CashRegister;
use App\Services\Oil\OilDrumService;
use App\Services\Sales\SaleCalculator;
use App\Services\SequenceService;
use App\Services\Stock\LotInput;
use App\Services\Stock\StockService;
use Illuminate\Support\Facades\DB;

/**
 * Return / credit note (D-15, §6.4): the only way to correct an invoice.
 * Returned goods go back in stock or are declared damaged; the money is
 * given back, deducted from the customer's debt, or kept as a voucher for
 * an exchange. Sellers ask the owner (approval); the owner does it at once.
 *
 * @phpstan-type ReturnLines array<int, array{quantity: int, restock?: bool}>  invoice line id => what comes back
 */
class CreateCreditNoteAction
{
    public function __construct(
        private StockService $stock,
        private SequenceService $sequences,
        private ApprovalService $approvals,
    ) {}

    /**
     * @param  ReturnLines  $lines
     * @return Sale|ApprovalRequest the credit note, or the pending request when the owner must approve
     */
    public function request(Sale $invoice, array $lines, RefundMethod $refund, string $reason, User $user): Sale|ApprovalRequest
    {
        $this->validate($invoice, $lines, $refund, $reason, $user);

        $payload = ['invoice_id' => $invoice->id, 'lines' => $lines, 'refund' => $refund->value, 'reason' => $reason];

        if ($user->can(Permission::ApprovalsDecide->value)) {
            return $this->execute($invoice, $lines, $refund, $reason, $user, $user);
        }

        return $this->approvals->request(ApprovalType::CreditNote, $reason, $payload, $user, $invoice->site, $invoice);
    }

    /** @param  ReturnLines  $lines */
    public function execute(Sale $invoice, array $lines, RefundMethod $refund, string $reason, User $requester, User $approver): Sale
    {
        return DB::transaction(function () use ($invoice, $lines, $refund, $reason, $requester, $approver) {
            $invoice = Sale::query()->with(['lines', 'site'])->lockForUpdate()->findOrFail($invoice->id);
            if ($refund === RefundMethod::Cash) {
                app(CashRegister::class)->assertOpen($invoice->site_id); // D-29
            }
            $this->validate($invoice, $lines, $refund, $reason, $requester, checkPermission: false);

            $note = Sale::query()->create([
                'kind' => SaleKind::CreditNote,
                'status' => SaleStatus::Draft,
                'site_id' => $invoice->site_id,
                'customer_id' => $invoice->customer_id,
                'customer_vehicle_id' => $invoice->customer_vehicle_id,
                'price_level' => $invoice->price_level,
                'original_sale_id' => $invoice->id,
                'refund_method' => $refund,
                'reason' => mb_substr(trim($reason), 0, 255),
                'seller_id' => $requester->id,
            ]);

            $order = 0;
            foreach ($lines as $lineId => $item) {
                $qty = (int) $item['quantity'];
                if ($qty <= 0) {
                    continue;
                }
                $line = $invoice->lines->firstWhere('id', (int) $lineId);
                // A litre line is returned whole (quantity 1 = all its litres).
                $totals = $line->kind === 'bulk_oil'
                    ? ['ht' => $line->total_ht_cents, 'vat' => $line->total_vat_cents, 'ttc' => $line->total_ttc_cents]
                    : SaleCalculator::split($line->unit_price_ttc_cents * $qty, $line->vat_rate_bp);
                $restock = $line->kind !== 'service' && (bool) ($item['restock'] ?? true);

                $note->lines()->create([
                    ...collect($line->getAttributes())->only(['product_id', 'used_tyre_id', 'bundle_id', 'oil_drum_id', 'quantity_ml', 'kind', 'description',
                        'list_price_ttc_cents', 'unit_price_ttc_cents', 'discount_bp', 'vat_rate_bp', 'unit_cost_cents'])->all(),
                    'quantity' => $qty,
                    'original_line_id' => $line->id,
                    'restock' => $line->kind === 'service' ? null : $restock,
                    'total_ht_cents' => $totals['ht'],
                    'total_vat_cents' => $totals['vat'],
                    'total_ttc_cents' => $totals['ttc'],
                    'sort_order' => $order++,
                ]);
            }
            $note->load('lines');

            foreach ($note->lines as $line) {
                $this->backInStock($note, $line, $approver);
            }

            $note->forceFill([
                'number' => $this->sequences->nextDocumentNumber(DocumentType::CreditNote),
                'status' => SaleStatus::Confirmed,
                'total_ht_cents' => (int) $note->lines->sum('total_ht_cents'),
                'total_vat_cents' => (int) $note->lines->sum('total_vat_cents'),
                'total_ttc_cents' => (int) $note->lines->sum('total_ttc_cents'),
                'confirmed_by' => $approver->id,
                'confirmed_at' => now(),
            ])->save();

            activity('sales')->causedBy($approver)->performedOn($note)->event('credit_note')
                ->withProperties(['number' => $note->number, 'invoice' => $invoice->number, 'total_ttc_cents' => $note->total_ttc_cents,
                    'refund' => $refund->value, 'requested_by' => $requester->id, 'reason' => $reason])
                ->tap(fn ($a) => $a->site_id = $note->site_id)->log('credit_note');

            return $note;
        });
    }

    /** Quantity of an invoice line still returnable (sold minus earlier credit notes). */
    public static function returnable(SaleLine $line): int
    {
        $returned = (int) SaleLine::query()->where('original_line_id', $line->id)
            ->whereHas('sale', fn ($q) => $q->where('status', SaleStatus::Confirmed->value))->sum('quantity');

        return $line->quantity - $returned;
    }

    private function backInStock(Sale $note, SaleLine $line, User $user): void
    {
        if ($line->kind === 'goods') {
            [$lots, $quantities] = $this->originalLots($note, $line);
            $this->stock->restock($line->product, $note->site, $line->quantity, StockMovementType::CustomerReturn,
                $line->unit_cost_cents, $user, $note->reason, $note, $lots, $quantities);
            if (! $line->restock) {
                $this->stock->issue($line->product, $note->site, $line->quantity, StockMovementType::Damaged, $user, $note->reason, $note, respectReservations: false);
            }
        } elseif ($line->kind === 'bulk_oil') {
            if ($line->restock) {
                app(OilDrumService::class)->giveBack(OilDrum::query()->findOrFail($line->oil_drum_id),
                    (int) $line->quantity_ml, $user, $note, $note->reason);
            }
        } elseif ($line->kind === 'used_tyre') {
            $tyre = UsedTyre::query()->lockForUpdate()->findOrFail($line->used_tyre_id);
            // A returned used tyre is checked again before being sold (§5.4).
            $tyre->forceFill(['status' => $line->restock ? UsedTyreStatus::PendingInspection : UsedTyreStatus::Scrapped, 'site_id' => $note->site_id])->save();
            foreach ($line->restock ? [1] : [1, -1] as $qty) {
                $m = new StockMovement(['used_tyre_id' => $tyre->id, 'site_id' => $note->site_id,
                    'type' => $qty > 0 ? StockMovementType::CustomerReturn : StockMovementType::Damaged, 'quantity' => $qty,
                    'unit_cost_cents' => $tyre->cost_cents, 'user_id' => $user->id, 'reason' => $note->reason]);
                $m->document()->associate($note);
                $m->save();
            }
        }
    }

    /**
     * New tyres come back with the DOT they left with (§5.3): the lots of the
     * original sale's exits, minus what earlier credit notes already returned.
     *
     * @return array{0: list<LotInput>, 1: list<int>}
     */
    private function originalLots(Sale $note, SaleLine $line): array
    {
        if (! $line->product->isNewTyre()) {
            return [[], []];
        }

        $exits = StockMovement::query()->with('lot')
            ->where('document_type', (new Sale)->getMorphClass())->where('document_id', $note->original_sale_id)
            ->where('product_id', $line->product_id)->where('type', StockMovementType::Sale->value)->whereNotNull('tyre_lot_id')
            ->orderByDesc('id')->get();
        $alreadyBack = (int) StockMovement::query()->where('document_type', (new Sale)->getMorphClass())
            ->whereIn('document_id', Sale::query()->where('original_sale_id', $note->original_sale_id)->where('id', '!=', $note->id)->select('id'))
            ->where('product_id', $line->product_id)->where('type', StockMovementType::CustomerReturn->value)->sum('quantity');

        $lots = [];
        $quantities = [];
        $left = $line->quantity;
        foreach ($exits as $exit) {
            $available = -$exit->quantity;
            $skip = min($alreadyBack, $available);
            $alreadyBack -= $skip;
            $take = min($left, $available - $skip);
            if ($take <= 0) {
                continue;
            }
            $lots[] = new LotInput($exit->lot?->dot_week, $exit->lot?->dot_year, $exit->lot?->supplier, $exit->tyre_lot_id, $exit->unit_cost_cents);
            $quantities[] = $take;
            $left -= $take;
            if ($left === 0) {
                break;
            }
        }

        if ($left > 0) { // exit without lot (should not happen): unknown DOT, never invented
            $lots[] = new LotInput(unitCostCents: $line->unit_cost_cents);
            $quantities[] = $left;
        }

        return [$lots, $quantities];
    }

    /** @param  ReturnLines  $lines */
    private function validate(Sale $invoice, array $lines, RefundMethod $refund, string $reason, User $user, bool $checkPermission = true): void
    {
        if ($checkPermission && (! $user->can(Permission::SalesCreate->value) || ! $user->canAccessSite($invoice->site_id))) {
            throw BusinessRuleException::make('app.errors.forbidden');
        }
        if (! $invoice->isKind(SaleKind::Invoice) || $invoice->status !== SaleStatus::Confirmed) {
            throw BusinessRuleException::make('sales.errors.credit_note_invoice');
        }
        if (trim($reason) === '') {
            throw BusinessRuleException::make('approvals.errors.reason_required');
        }
        if ($refund !== RefundMethod::Cash && ! $invoice->customer_id) {
            throw BusinessRuleException::make('sales.errors.refund_needs_customer');
        }

        $invoice->loadMissing('lines');
        $any = false;
        foreach ($lines as $lineId => $item) {
            $qty = (int) ($item['quantity'] ?? 0);
            if ($qty === 0) {
                continue;
            }
            $line = $invoice->lines->firstWhere('id', (int) $lineId);
            if (! $line || $line->kind === 'trade_in' || $qty < 0 || $qty > self::returnable($line)) {
                throw BusinessRuleException::make('sales.errors.return_quantity', ['line' => $line?->description ?? '?']);
            }
            $any = true;
        }
        if (! $any) {
            throw BusinessRuleException::make('sales.errors.return_empty');
        }
    }
}
