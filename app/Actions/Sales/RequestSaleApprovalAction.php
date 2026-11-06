<?php

namespace App\Actions\Sales;

use App\Enums\ApprovalStatus;
use App\Enums\ApprovalType;
use App\Models\ApprovalRequest;
use App\Models\Sale;
use App\Models\User;
use App\Services\Approvals\ApprovalService;
use App\Services\Sales\SaleRules;

/** Asks the owner for the approvals a draft sale needs (one request per type). */
class RequestSaleApprovalAction
{
    public function __construct(private ApprovalService $approvals, private SaleRules $rules) {}

    /**
     * @param  list<ApprovalType>  $types
     * @return list<ApprovalRequest>
     */
    public function execute(Sale $sale, array $types, string $reason, User $user, int $creditCents = 0): array
    {
        $sale->loadMissing(['lines', 'customer', 'site']);
        $payload = [
            'sale_id' => $sale->id,
            'fingerprint' => $this->rules->fingerprint($sale),
            'credit_cents' => $creditCents,
            'total_cents' => $sale->total_ttc_cents,
            'customer' => $sale->customer?->name,
            'max_discount_bp' => (int) $sale->lines->max('discount_bp'),
        ];

        $requests = [];
        foreach ($types as $type) {
            // A pending request for the same draft is replaced by the new one.
            ApprovalRequest::query()->where('subject_type', $sale->getMorphClass())->where('subject_id', $sale->id)
                ->where('type', $type->value)->where('status', ApprovalStatus::Pending->value)
                ->update(['status' => ApprovalStatus::Cancelled->value]);

            $requests[] = $this->approvals->request($type, $reason, $payload, $user, $sale->site, $sale);
        }

        return $requests;
    }
}
