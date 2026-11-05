<?php

namespace App\Actions\Stock;

use App\Enums\ApprovalType;
use App\Enums\Permission;
use App\Exceptions\BusinessRuleException;
use App\Models\ApprovalRequest;
use App\Models\Product;
use App\Models\Site;
use App\Models\User;
use App\Services\Approvals\ApprovalService;

/**
 * Stock corrections and damaged goods never change stock directly: they are
 * approval requests, executed once approved (§4, §6.1).
 */
class RequestStockAdjustmentAction
{
    public function __construct(private ApprovalService $approvals) {}

    public function execute(Product $product, Site $site, int $delta, string $reason, User $user, bool $damaged = false): ApprovalRequest
    {
        if (! $user->can(Permission::StockAdjust->value) || ! $user->canAccessSite($site)) {
            throw BusinessRuleException::make('app.errors.forbidden');
        }

        if (! $product->isStocked()) {
            throw BusinessRuleException::make('stock.errors.not_stocked', ['product' => $product->name]);
        }

        if ($delta === 0 || ($damaged && $delta > 0)) {
            throw BusinessRuleException::make('stock.errors.invalid_adjustment');
        }

        return $this->approvals->request(
            $damaged ? ApprovalType::DamagedStock : ApprovalType::StockAdjustment,
            $reason,
            ['product_id' => $product->id, 'site_id' => $site->id, 'delta' => $delta],
            $user,
            $site,
            $product,
        );
    }
}
