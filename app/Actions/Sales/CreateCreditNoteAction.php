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
use App\Models\Sale;
use App\Models\SaleLine;
use App\Models\StockMovement;
use App\Models\UsedTyre;
use App\Models\User;
use App\Services\Approvals\ApprovalService;
use App\Services\Sales\SaleCalculator;
use App\Services\SequenceService;
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
                $totals = SaleCalculator::split($line->unit_price_ttc_cents * $qty, $line->vat_rate_bp);
                $restock = $line->kind !== 'service' && (bool) ($item['restock'] ?? true);

                $note->lines()->create([
                    ...collect($line->getAttributes())->only(['product_id', 'used_tyre_id', 'bundle_id', 'kind', 'description',
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
            $this->stock->restock($line->product, $note->site, $line->quantity, StockMovementType::CustomerReturn,
                $line->unit_cost_cents, $user, $note->reason, $note);
            if (! $line->restock) {
                $this->stock->issue($line->product, $note->site, $line->quantity, StockMovementType::Damaged, $user, $note->reason, $note, respectReservations: false);
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
