<?php

namespace App\Services\Approvals;

use App\Enums\StockMovementType;
use App\Models\ApprovalRequest;
use App\Models\Product;
use App\Models\Site;
use App\Models\User;
use App\Services\Stock\StockService;

/** Payload: product_id, site_id, delta (signed). Also used for damaged goods (delta < 0). */
class StockAdjustmentHandler implements ApprovalHandler
{
    public function __construct(private StockService $stock, private bool $damaged = false) {}

    public function execute(ApprovalRequest $request, User $approver): void
    {
        $p = $request->payload;
        $this->stock->adjust(
            Product::query()->findOrFail($p['product_id']),
            Site::query()->findOrFail($p['site_id']),
            (int) $p['delta'],
            $request->requester ?? $approver,
            $request->reason,
            $request,
            $this->damaged ? StockMovementType::Damaged : StockMovementType::InventoryAdjustment,
        );
    }

    public function summary(ApprovalRequest $request): string
    {
        $p = $request->payload;

        return __('approvals.summaries.'.($this->damaged ? 'damaged_stock' : 'stock_adjustment'), [
            'product' => Product::query()->whereKey($p['product_id'])->value('name'),
            'site' => Site::query()->whereKey($p['site_id'])->value('code'),
            'delta' => ($p['delta'] > 0 ? '+' : '').$p['delta'],
        ]);
    }
}
