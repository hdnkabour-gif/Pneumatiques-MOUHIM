<?php

namespace App\Actions\Stock;

use App\Enums\Permission;
use App\Enums\StockMovementType;
use App\Exceptions\BusinessRuleException;
use App\Models\Product;
use App\Models\Site;
use App\Models\User;
use App\Services\Stock\LotInput;
use App\Services\Stock\StockService;

/**
 * Simple goods receipt / opening stock (D-39), until the purchasing module
 * (phase 6) replaces it with receipts against purchase orders.
 */
class ReceiveStockAction
{
    public function __construct(private StockService $stock) {}

    public function execute(
        Product $product,
        Site $site,
        int $quantity,
        ?int $unitCostCents,
        User $user,
        bool $opening = false,
        ?int $dotWeek = null,
        ?int $dotYear = null,
        ?string $supplier = null,
        ?string $reason = null,
    ): void {
        if (! $user->can(Permission::StockReceive->value) || ! $user->canAccessSite($site)) {
            throw BusinessRuleException::make('app.errors.forbidden');
        }

        if ($dotWeek !== null && ($dotWeek < 1 || $dotWeek > 53)) {
            throw BusinessRuleException::make('stock.errors.invalid_dot');
        }

        $this->stock->receive(
            $product,
            $site,
            $quantity,
            $unitCostCents,
            $opening ? StockMovementType::OpeningStock : StockMovementType::PurchaseReceipt,
            $user,
            $reason ?? $supplier,
            null,
            new LotInput($dotWeek, $dotYear, $supplier),
        );
    }
}
