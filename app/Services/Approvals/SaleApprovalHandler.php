<?php

namespace App\Services\Approvals;

use App\Enums\ApprovalType;
use App\Exceptions\BusinessRuleException;
use App\Models\ApprovalRequest;
use App\Models\Sale;
use App\Models\User;
use App\Services\Sales\SaleRules;
use App\Support\Money;

/**
 * Discount / price / below-cost / credit approvals on a draft sale.
 * Payload: sale_id, fingerprint, credit_cents. Granting records the approval
 * on the draft; the seller then confirms the sale as usual.
 */
class SaleApprovalHandler implements ApprovalHandler
{
    public function __construct(private ApprovalType $type) {}

    public function execute(ApprovalRequest $request, User $approver): void
    {
        $p = $request->payload;
        $sale = Sale::query()->lockForUpdate()->find($p['sale_id']);

        if (! $sale || ! $sale->isDraft() || app(SaleRules::class)->fingerprint($sale) !== $p['fingerprint']) {
            throw BusinessRuleException::make('sales.errors.approval_outdated');
        }

        $approvals = $sale->approvals ?? [];
        $approvals[$this->type->value] = [
            'request_id' => $request->id,
            'by' => $approver->id,
            'fingerprint' => $p['fingerprint'],
            'credit_cents' => (int) ($p['credit_cents'] ?? 0),
        ];
        $sale->forceFill(['approvals' => $approvals])->save();
    }

    public function summary(ApprovalRequest $request): string
    {
        $p = $request->payload;

        return __('approvals.summaries.sale', [
            'type' => $this->type->label(),
            'total' => Money::format((int) ($p['total_cents'] ?? 0)),
            'customer' => $p['customer'] ?? '—',
            'credit' => Money::format((int) ($p['credit_cents'] ?? 0)),
            'discount' => number_format(((int) ($p['max_discount_bp'] ?? 0)) / 100, 1, ',', ' '),
        ]);
    }
}
