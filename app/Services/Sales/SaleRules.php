<?php

namespace App\Services\Sales;

use App\Enums\ApprovalType;
use App\Enums\Permission;
use App\Enums\RoleName;
use App\Models\Product;
use App\Models\Sale;
use App\Models\UsedTyre;
use App\Models\User;
use App\Services\SettingsService;

/**
 * Which approvals a sale needs (§4, D-18, D-21): discount above the role
 * cap, price raised above the catalogue, price below cost, credit above the
 * customer's limit. An approval is tied to a fingerprint of the sale, so
 * changing the cart after the approval asks for a new one.
 */
class SaleRules
{
    public function __construct(private SettingsService $settings) {}

    /** Null = no cap (the owner, or anyone allowed to decide approvals). */
    public function discountCapBp(User $user): ?int
    {
        if ($user->can(Permission::ApprovalsDecide->value)) {
            return null;
        }

        return (int) $this->settings->get($user->hasRole(RoleName::SiteManager->value)
            ? 'sales.discount_cap_bp.site_manager'
            : 'sales.discount_cap_bp.seller');
    }

    /** @return list<ApprovalType> */
    public function required(Sale $sale, User $user, int $creditCents): array
    {
        $sale->loadMissing('lines');
        $types = [];
        $cap = $this->discountCapBp($user);

        if ($cap !== null && $sale->lines->contains(fn ($l) => $l->discount_bp > $cap)) {
            $types[] = ApprovalType::Discount;
        }

        if ($sale->lines->contains(fn ($l) => $l->unit_price_ttc_cents > $l->list_price_ttc_cents)) {
            $types[] = ApprovalType::PriceOverride;
        }

        if ($this->belowCost($sale)) {
            $types[] = ApprovalType::BelowCost;
        }

        if ($creditCents > 0 && $this->creditAboveLimit($sale, $creditCents)) {
            $types[] = ApprovalType::CreditLimit;
        }

        return array_values(array_filter($types, fn (ApprovalType $t) => ! $this->isGranted($sale, $t, $creditCents)));
    }

    public function fingerprint(Sale $sale): string
    {
        $sale->loadMissing('lines');

        return sha1(json_encode([
            $sale->customer_id,
            $sale->lines->map(fn ($l) => [$l->product_id, $l->used_tyre_id, $l->quantity, $l->unit_price_ttc_cents])->all(),
        ]));
    }

    public function isGranted(Sale $sale, ApprovalType $type, int $creditCents = 0): bool
    {
        $granted = $sale->approvals[$type->value] ?? null;

        if (! $granted || ($granted['fingerprint'] ?? null) !== $this->fingerprint($sale)) {
            return false;
        }

        return $type !== ApprovalType::CreditLimit || $creditCents <= (int) ($granted['credit_cents'] ?? 0);
    }

    /** Unit price HT under the average cost. Unknown cost = cannot be checked, never guessed. */
    private function belowCost(Sale $sale): bool
    {
        $costs = Product::query()->whereKey($sale->lines->pluck('product_id')->filter())->pluck('average_cost_cents', 'id');
        $usedCosts = UsedTyre::query()->whereKey($sale->lines->pluck('used_tyre_id')->filter())->pluck('cost_cents', 'id');

        foreach ($sale->lines as $line) {
            $cost = $line->used_tyre_id ? $usedCosts[$line->used_tyre_id] ?? null : ($line->kind === 'goods' ? $costs[$line->product_id] ?? null : null);
            if ($cost !== null && $line->total_ht_cents < $cost * $line->quantity) {
                return true;
            }
        }

        return false;
    }

    /**
     * D-21, D-54: a customer without a limit has a limit of zero, so any
     * credit needs the owner's approval until the owner sets a limit.
     */
    private function creditAboveLimit(Sale $sale, int $creditCents): bool
    {
        $customer = $sale->customer;
        $limit = (int) ($customer?->credit_limit_cents ?? 0);

        return $customer->outstandingCents() + $creditCents > $limit;
    }
}
