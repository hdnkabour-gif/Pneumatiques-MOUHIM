<?php

namespace App\Services\Approvals;

use App\Actions\Sales\CreateCreditNoteAction;
use App\Enums\RefundMethod;
use App\Models\ApprovalRequest;
use App\Models\Sale;
use App\Models\User;
use App\Support\Money;

/** Payload: invoice_id, lines, refund, reason. Granting issues the credit note. */
class CreditNoteHandler implements ApprovalHandler
{
    public function execute(ApprovalRequest $request, User $approver): void
    {
        $p = $request->payload;
        app(CreateCreditNoteAction::class)->execute(
            Sale::query()->findOrFail($p['invoice_id']),
            $p['lines'],
            RefundMethod::from($p['refund']),
            $p['reason'],
            $request->requester ?? $approver,
            $approver,
        );
    }

    public function summary(ApprovalRequest $request): string
    {
        $p = $request->payload;
        $invoice = Sale::query()->with('lines')->find($p['invoice_id']);
        $total = 0;
        foreach ($p['lines'] as $id => $item) {
            $total += (int) ($invoice?->lines->firstWhere('id', (int) $id)?->unit_price_ttc_cents ?? 0) * (int) $item['quantity'];
        }

        return __('approvals.summaries.credit_note', [
            'invoice' => $invoice?->number, 'total' => Money::format($total), 'refund' => RefundMethod::from($p['refund'])->label(),
        ]);
    }
}
