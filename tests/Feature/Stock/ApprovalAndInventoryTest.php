<?php

use App\Actions\Inventory\InventoryCountActions;
use App\Actions\Stock\RequestStockAdjustmentAction;
use App\Enums\ApprovalStatus;
use App\Enums\InventoryCountStatus;
use App\Enums\RoleName;
use App\Enums\StockMovementType;
use App\Exceptions\BusinessRuleException;
use App\Models\Product;
use App\Models\StockMovement;
use App\Services\Approvals\ApprovalService;
use App\Services\Stock\StockService;
use App\Services\Stock\StockVerifier;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\RateLimiter;

beforeEach(function () {
    $this->owner = owner();
    $this->storekeeper = userWithRole(RoleName::Storekeeper, 'DEP');
    $this->stock = app(StockService::class);
    $this->product = Product::factory()->create();
    $this->stock->receive($this->product, site('DEP'), 20, 1000);
});

it('does not change stock until the owner approves an adjustment', function () {
    $request = app(RequestStockAdjustmentAction::class)->execute($this->product, site('DEP'), -3, 'Casse en rayon', $this->storekeeper, damaged: true);

    expect($request->status)->toBe(ApprovalStatus::Pending)
        ->and($this->stock->quantityAt($this->product, site('DEP')))->toBe(20)
        ->and($this->owner->notifications()->count())->toBe(1);

    app(ApprovalService::class)->decide($request, $this->owner, true, 'OK');

    expect($request->fresh()->status)->toBe(ApprovalStatus::Approved)
        ->and($this->stock->quantityAt($this->product, site('DEP')))->toBe(17)
        ->and(StockMovement::query()->where('type', StockMovementType::Damaged->value)->sum('quantity'))->toEqual(-3)
        ->and($this->storekeeper->notifications()->count())->toBe(1);
});

it('leaves stock untouched when the owner refuses', function () {
    $request = app(RequestStockAdjustmentAction::class)->execute($this->product, site('DEP'), 5, 'Trouvé en réserve', $this->storekeeper);
    app(ApprovalService::class)->decide($request, $this->owner, false, 'Recomptez');

    expect($request->fresh()->status)->toBe(ApprovalStatus::Rejected)
        ->and($this->stock->quantityAt($this->product, site('DEP')))->toBe(20);
});

it('requires a reason and forbids deciding twice', function () {
    expect(fn () => app(RequestStockAdjustmentAction::class)->execute($this->product, site('DEP'), 1, '  ', $this->storekeeper))
        ->toThrow(BusinessRuleException::class);

    $request = app(RequestStockAdjustmentAction::class)->execute($this->product, site('DEP'), 1, 'Erreur', $this->storekeeper);
    app(ApprovalService::class)->decide($request, $this->owner, true);

    app(ApprovalService::class)->decide($request, $this->owner, true);
})->throws(BusinessRuleException::class);

it('lets only approvers decide', function () {
    $request = app(RequestStockAdjustmentAction::class)->execute($this->product, site('DEP'), 1, 'Erreur', $this->storekeeper);

    app(ApprovalService::class)->decide($request, userWithRole(RoleName::SiteManager, 'DEP'), true);
})->throws(BusinessRuleException::class);

it('approves on site with the owner PIN, and locks after 5 wrong PINs', function () {
    $this->owner->forceFill(['approval_pin' => Hash::make('2468')])->save();
    $request = app(RequestStockAdjustmentAction::class)->execute($this->product, site('DEP'), -1, 'Casse', $this->storekeeper, damaged: true);

    foreach (range(1, 5) as $_) {
        try {
            app(ApprovalService::class)->decideWithPin($request, '0000');
        } catch (BusinessRuleException) {
        }
    }
    expect(fn () => app(ApprovalService::class)->decideWithPin($request, '2468'))->toThrow(BusinessRuleException::class, 'essais');

    RateLimiter::clear('approval-pin:'.$request->id);
    $decided = app(ApprovalService::class)->decideWithPin($request, '2468');

    expect($decided->status)->toBe(ApprovalStatus::Approved)
        ->and($decided->decided_via)->toBe('pin')
        ->and($decided->decided_by)->toBe($this->owner->id);
});

it('executes immediately when the owner makes the request', function () {
    $request = app(RequestStockAdjustmentAction::class)->execute($this->product, site('DEP'), 2, 'Comptage', $this->owner);

    expect($request->status)->toBe(ApprovalStatus::Approved)
        ->and($request->decided_via)->toBe('self')
        ->and($this->stock->quantityAt($this->product, site('DEP')))->toBe(22);
});

it('runs an inventory count and adjusts stock only after validation', function () {
    $other = Product::factory()->create();
    $this->stock->receive($other, site('DEP'), 5, 500);
    $actions = app(InventoryCountActions::class);

    $count = $actions->create(site('DEP'), $this->storekeeper);
    $lines = $count->lines()->get()->keyBy('product_id');
    expect($lines[$this->product->id]->expected_quantity)->toBe(20);

    $actions->saveCounts($count, [$lines[$this->product->id]->id => 18, $lines[$other->id]->id => 5], $this->storekeeper);
    $actions->submit($count, $this->storekeeper);
    expect($this->stock->quantityAt($this->product, site('DEP')))->toBe(20);

    // The storekeeper cannot validate his own count.
    expect(fn () => $actions->approve($count->fresh(), $this->storekeeper))->toThrow(BusinessRuleException::class);

    $actions->approve($count->fresh(), $this->owner);

    expect($count->fresh()->status)->toBe(InventoryCountStatus::Approved)
        ->and($this->stock->quantityAt($this->product, site('DEP')))->toBe(18)
        ->and($this->stock->quantityAt($other, site('DEP')))->toBe(5)
        ->and(app(StockVerifier::class)->differences())->toBe([]);
});
