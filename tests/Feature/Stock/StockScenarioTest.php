<?php

use App\Actions\Stock\ReceiveStockAction;
use App\Actions\Transfers\ReceiveTransferAction;
use App\Actions\Transfers\SendTransferAction;
use App\Enums\RoleName;
use App\Enums\StockMovementType;
use App\Exceptions\InsufficientStockException;
use App\Models\Product;
use App\Models\StockMovement;
use App\Services\Stock\StockService;
use App\Services\Stock\StockVerifier;
use Illuminate\Support\Facades\DB;

/*
| Cahier des charges §21 – "Stock multi-sites", phase 2 acceptance scenario.
*/

it('runs the multi-site stock scenario end to end', function () {
    $owner = owner();
    $storekeeper = userWithRole(RoleName::Storekeeper, 'DEP');
    $managerM01 = userWithRole(RoleName::SiteManager, 'M01');
    $managerM02 = userWithRole(RoleName::SiteManager, 'M02');
    $stock = app(StockService::class);
    $tyre = Product::factory()->tyre(205, 55, '16')->create();

    // 1. 100 tyres received at the depot.
    app(ReceiveStockAction::class)->execute($tyre, site('DEP'), 100, 45000, $storekeeper, dotWeek: 10, dotYear: 2026, supplier: 'Fournisseur A');

    // 2. 20 to M01 and 15 to M02, received.
    $t1 = app(SendTransferAction::class)->execute(site('DEP'), site('M01'), [$tyre->id => 20], $storekeeper);
    $t2 = app(SendTransferAction::class)->execute(site('DEP'), site('M02'), [$tyre->id => 15], $storekeeper);
    app(ReceiveTransferAction::class)->execute($t1, $managerM01);
    app(ReceiveTransferAction::class)->execute($t2, $managerM02);

    // 3. DEP 65, M01 20, M02 15, total 100.
    expect($stock->quantityAt($tyre, site('DEP')))->toBe(65)
        ->and($stock->quantityAt($tyre, site('M01')))->toBe(20)
        ->and($stock->quantityAt($tyre, site('M02')))->toBe(15)
        ->and((int) $tyre->stockLevels()->sum('quantity'))->toBe(100);

    // 4. Sale of 4 at M01 (sales arrive in phase 3: the stock exit is what is tested here).
    $stock->issue($tyre, site('M01'), 4, StockMovementType::Sale, $owner, 'Vente test');
    expect($stock->quantityAt($tyre, site('M01')))->toBe(16)
        ->and($stock->quantityAt($tyre, site('DEP')))->toBe(65)
        ->and($stock->quantityAt($tyre, site('M02')))->toBe(15);

    // 5. Every movement is in the ledger and stock:verify finds no difference.
    expect(StockMovement::query()->where('product_id', $tyre->id)->sum('quantity'))->toEqual(96)
        ->and(StockMovement::query()->where('type', StockMovementType::TransferOut->value)->count())->toBe(2)
        ->and(StockMovement::query()->where('type', StockMovementType::TransferIn->value)->count())->toBe(2)
        ->and(app(StockVerifier::class)->differences())->toBe([]);
    $this->artisan('stock:verify')->assertSuccessful();

    // 6. Selling 30 at M02 (only 15) is refused, nothing changes.
    expect(fn () => $stock->issue($tyre, site('M02'), 30, StockMovementType::Sale, $owner))
        ->toThrow(InsufficientStockException::class);
    expect($stock->quantityAt($tyre, site('M02')))->toBe(15);

    // Transfers are valued at the average cost: no margin impact (D-09).
    expect($tyre->fresh()->average_cost_cents)->toBe(45000);
});

it('reports a cache that disagrees with the ledger', function () {
    $tyre = Product::factory()->create();
    app(ReceiveStockAction::class)->execute($tyre, site('DEP'), 5, 1000, owner());

    DB::table('stock_levels')->where('product_id', $tyre->id)->update(['quantity' => 7]);

    expect(app(StockVerifier::class)->differences())->toHaveCount(1);
    $this->artisan('stock:verify')->assertFailed();
});
