<?php

use App\Actions\Transfers\CancelTransferAction;
use App\Actions\Transfers\ConvertReplenishmentRequestAction;
use App\Actions\Transfers\CreateReplenishmentRequestAction;
use App\Actions\Transfers\ReceiveTransferAction;
use App\Actions\Transfers\SendTransferAction;
use App\Enums\ReplenishmentStatus;
use App\Enums\RoleName;
use App\Enums\TransferStatus;
use App\Exceptions\BusinessRuleException;
use App\Models\Product;
use App\Models\TyreLot;
use App\Services\Stock\LotInput;
use App\Services\Stock\ReplenishmentSuggester;
use App\Services\Stock\StockService;
use App\Services\Stock\StockVerifier;

beforeEach(function () {
    $this->stock = app(StockService::class);
    $this->storekeeper = userWithRole(RoleName::Storekeeper, 'DEP');
    $this->managerM01 = userWithRole(RoleName::SiteManager, 'M01');
    $this->product = Product::factory()->create();
    $this->stock->receive($this->product, site('DEP'), 50, 1000);
});

it('numbers transfers per year (D-14)', function () {
    $t = app(SendTransferAction::class)->execute(site('DEP'), site('M01'), [$this->product->id => 1], $this->storekeeper);

    expect($t->number)->toBe('TR-'.now()->year.'-000001')
        ->and($t->status)->toBe(TransferStatus::InTransit);
});

it('records a shortfall at reception with a mandatory reason', function () {
    $t = app(SendTransferAction::class)->execute(site('DEP'), site('M01'), [$this->product->id => 10], $this->storekeeper);
    $line = $t->lines->first();

    expect(fn () => app(ReceiveTransferAction::class)->execute($t, $this->managerM01, [$line->id => 8]))
        ->toThrow(BusinessRuleException::class, 'motif');

    app(ReceiveTransferAction::class)->execute($t, $this->managerM01, [$line->id => 8], [$line->id => '2 cassés pendant le transport']);

    expect($this->stock->quantityAt($this->product, site('M01')))->toBe(8)
        ->and($this->stock->quantityAt($this->product, site('DEP')))->toBe(40)
        ->and($line->fresh()->discrepancy_reason)->toBe('2 cassés pendant le transport')
        ->and(app(StockVerifier::class)->differences())->toBe([]);
});

it('cannot receive a transfer twice', function () {
    $t = app(SendTransferAction::class)->execute(site('DEP'), site('M01'), [$this->product->id => 1], $this->storekeeper);
    app(ReceiveTransferAction::class)->execute($t, $this->managerM01);

    app(ReceiveTransferAction::class)->execute($t, $this->managerM01);
})->throws(BusinessRuleException::class);

it('returns the goods to the source when a transfer is cancelled', function () {
    $t = app(SendTransferAction::class)->execute(site('DEP'), site('M01'), [$this->product->id => 10], $this->storekeeper);
    expect($this->stock->quantityAt($this->product, site('DEP')))->toBe(40);

    app(CancelTransferAction::class)->execute($t, $this->storekeeper, 'Erreur de préparation');

    expect($this->stock->quantityAt($this->product, site('DEP')))->toBe(50)
        ->and($t->fresh()->status)->toBe(TransferStatus::Cancelled);
});

it('keeps tyre DOT lots through a transfer', function () {
    $tyre = Product::factory()->tyre()->create();
    $this->stock->receive($tyre, site('DEP'), 2, 40000, lot: new LotInput(5, 2024));
    $this->stock->receive($tyre, site('DEP'), 5, 40000, lot: new LotInput(20, 2026));

    $t = app(SendTransferAction::class)->execute(site('DEP'), site('M01'), [$tyre->id => 4], $this->storekeeper);
    app(ReceiveTransferAction::class)->execute($t, $this->managerM01);

    $m01Lots = TyreLot::query()->where(['product_id' => $tyre->id, 'site_id' => site('M01')->id])->orderBy('dot_year')->get();
    expect($m01Lots->map(fn ($l) => [$l->dotLabel(), $l->quantity])->all())->toBe([['0524', 2], ['2026', 2]])
        ->and(app(StockVerifier::class)->differences())->toBe([]);
});

it('refuses a transfer by a user without the permission or the site', function () {
    $seller = userWithRole(RoleName::Seller, 'DEP');
    expect(fn () => app(SendTransferAction::class)->execute(site('DEP'), site('M01'), [$this->product->id => 1], $seller))
        ->toThrow(BusinessRuleException::class);

    // A M01 manager cannot receive a transfer addressed to M02.
    $t = app(SendTransferAction::class)->execute(site('DEP'), site('M02'), [$this->product->id => 1], $this->storekeeper);
    expect(fn () => app(ReceiveTransferAction::class)->execute($t, $this->managerM01))->toThrow(BusinessRuleException::class);
});

it('suggests replenishment for products under the store minimum', function () {
    $this->product->thresholds()->create(['site_id' => site('M01')->id, 'min_quantity' => 12]);
    $this->stock->receive($this->product, site('M01'), 2, 1000);

    $suggestions = app(ReplenishmentSuggester::class)->forSite(site('M01'), site('DEP'));

    expect($suggestions)->toHaveCount(1)
        ->and($suggestions->first()->suggested)->toBe(10);
});

it('turns a store request into a transfer from the depot', function () {
    $request = app(CreateReplenishmentRequestAction::class)->execute(site('M01'), site('DEP'), [$this->product->id => 6], $this->managerM01);
    expect($request->number)->toStartWith('DR-');

    $transfer = app(ConvertReplenishmentRequestAction::class)->execute($request, $this->storekeeper);

    expect($request->fresh()->status)->toBe(ReplenishmentStatus::Converted)
        ->and($transfer->to_site_id)->toBe(site('M01')->id)
        ->and($transfer->lines->first()->quantity_sent)->toBe(6)
        ->and($this->stock->quantityAt($this->product, site('DEP')))->toBe(44);
});
