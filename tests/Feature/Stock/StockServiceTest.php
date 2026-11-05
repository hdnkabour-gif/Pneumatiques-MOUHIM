<?php

use App\Enums\StockMovementType;
use App\Exceptions\BusinessRuleException;
use App\Exceptions\InsufficientStockException;
use App\Models\Product;
use App\Models\StockMovement;
use App\Services\Stock\LotInput;
use App\Services\Stock\StockService;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;

beforeEach(fn () => $this->stock = app(StockService::class));

it('computes the weighted average cost across receipts and sites', function () {
    $p = Product::factory()->create();

    $this->stock->receive($p, site('DEP'), 10, 1000);
    $this->stock->receive($p, site('M01'), 30, 2000);

    // (10 × 10.00 + 30 × 20.00) / 40 = 17.50
    expect($p->fresh()->average_cost_cents)->toBe(1750);

    // Exits are valued at the average and do not change it.
    $out = $this->stock->issue($p, site('M01'), 5, StockMovementType::Sale);
    expect($out->first()->unit_cost_cents)->toBe(1750)
        ->and($p->fresh()->average_cost_cents)->toBe(1750);
});

it('rounds the average cost to the nearest cent', function () {
    $p = Product::factory()->create();
    $this->stock->receive($p, site('DEP'), 2, 1000);
    $this->stock->receive($p, site('DEP'), 1, 1001);

    expect($p->fresh()->average_cost_cents)->toBe(1000); // 3001 / 3 = 1000.33
});

it('never guesses an unknown cost', function () {
    $p = Product::factory()->create();
    $this->stock->receive($p, site('DEP'), 5, null, StockMovementType::OpeningStock);
    expect($p->fresh()->average_cost_cents)->toBeNull();

    $this->stock->receive($p, site('DEP'), 5, 2000);
    expect($p->fresh()->average_cost_cents)->toBeNull();

    // Once the unknown-cost stock is gone, a new receipt sets a known cost again.
    $this->stock->issue($p, site('DEP'), 10, StockMovementType::Sale);
    $this->stock->receive($p, site('DEP'), 1, 3000);
    expect($p->fresh()->average_cost_cents)->toBe(3000);
});

it('suggests a selling price only with a known cost and margin', function () {
    $p = Product::factory()->create();
    expect($p->suggestedPriceCents())->toBeNull();

    $this->stock->receive($p, site('DEP'), 1, 10000);
    $p->refresh();
    expect($p->suggestedPriceCents())->toBeNull(); // margin empty (D-40)

    $p->category->update(['default_margin_bp' => 2500]);
    expect($p->fresh()->suggestedPriceCents())->toBe(12500);
});

it('issues new tyres from the oldest DOT first', function () {
    $tyre = Product::factory()->tyre()->create();
    $this->stock->receive($tyre, site('DEP'), 4, 40000, lot: new LotInput(10, 2026));
    $this->stock->receive($tyre, site('DEP'), 4, 40000, lot: new LotInput(5, 2024));
    $this->stock->receive($tyre, site('DEP'), 4, 40000, lot: new LotInput(30, 2025));

    $movements = $this->stock->issue($tyre, site('DEP'), 6, StockMovementType::Sale);

    expect($movements->map(fn ($m) => [$m->lot->dotLabel(), $m->quantity])->all())
        ->toBe([['0524', -4], ['3025', -2]]);
});

it('refuses to make a site stock negative', function () {
    $p = Product::factory()->create();
    $this->stock->receive($p, site('DEP'), 3, 1000);

    expect(fn () => $this->stock->issue($p, site('DEP'), 4, StockMovementType::Sale))
        ->toThrow(InsufficientStockException::class, 'disponible : 3');
    expect($this->stock->quantityAt($p, site('DEP')))->toBe(3);
});

it('does not hold stock for services', function () {
    $service = Product::factory()->service()->create();

    $this->stock->receive($service, site('M01'), 1, 100);
})->throws(BusinessRuleException::class);

it('keeps the stock ledger append-only', function () {
    $p = Product::factory()->create();
    $this->stock->receive($p, site('DEP'), 1, 1000);
    $movement = StockMovement::query()->firstOrFail();

    expect(fn () => $movement->update(['quantity' => 5]))->toThrow(LogicException::class)
        ->and(fn () => DB::table('stock_movements')->delete())->toThrow(QueryException::class, 'append-only');
});

it('protects stock levels against negative values at database level', function () {
    $p = Product::factory()->create();
    $this->stock->receive($p, site('DEP'), 1, 1000);

    DB::table('stock_levels')->update(['quantity' => -1]);
})->throws(QueryException::class);
