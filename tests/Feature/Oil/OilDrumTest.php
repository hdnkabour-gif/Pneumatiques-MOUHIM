<?php

use App\Actions\Sales\ConfirmSaleAction;
use App\Actions\Sales\CreateCreditNoteAction;
use App\Actions\Sales\SaveSaleDraftAction;
use App\Enums\OilDrumStatus;
use App\Enums\RefundMethod;
use App\Enums\RoleName;
use App\Enums\Unit;
use App\Exceptions\BusinessRuleException;
use App\Models\Customer;
use App\Models\OilDrumMovement;
use App\Models\Product;
use App\Models\StockLevel;
use App\Models\User;
use App\Services\Oil\OilDrumService;
use App\Services\Stock\StockService;
use App\Services\Stock\StockVerifier;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;

beforeEach(function () {
    $this->owner = owner();
    $this->seller = userWithRole(RoleName::Seller, 'M01');
    $this->drums = app(OilDrumService::class);
    // Bulk oil: price per litre (TTC). Sealed cans are a separate product.
    $this->bulk = Product::factory()->create(['name' => 'Huile 10W40 vrac', 'unit' => Unit::Litre, 'price_retail_cents' => 6000]);
    $this->receive = fn (int $litres = 208, int $cost = 832000, int $extra = 0, ?int $receivedL = null, string $site = 'M01') => $this->drums->receive(
        $this->bulk, site($site), ['capacity_ml' => $litres * 1000, 'received_ml' => $receivedL ? $receivedL * 1000 : null, 'purchase_cost_cents' => $cost, 'extra_cost_cents' => $extra], $this->owner);
    $this->sell = function (array $lines, ?User $user = null) {
        $draft = app(SaveSaleDraftAction::class)->execute(['site_id' => site('M01')->id, 'customer_id' => Customer::factory()->create()->id, 'lines' => $lines], $user ?? $this->seller);

        return app(ConfirmSaleAction::class)->execute($draft, [['method' => 'cash', 'amount_cents' => $draft->total_ttc_cents]], $user ?? $this->seller);
    };
});

it('receives a drum with its own number and ledger (1)', function () {
    $drum = ($this->receive)();

    expect($drum->code)->toBe('FUT-000001')
        ->and($drum->status)->toBe(OilDrumStatus::Closed)
        ->and($drum->remaining_ml)->toBe(208000)
        ->and($drum->movements)->toHaveCount(1);
});

it('computes the litre cost from purchase + fees ÷ litres really received (2)', function () {
    // 8 320 + 180 MAD of transport for 200 L really received (not the nominal 208 L) = 42,50 MAD/L.
    $drum = ($this->receive)(208, 832000, 18000, 200);

    expect($drum->cost_per_litre_cents)->toBe(4250)->and($drum->received_ml)->toBe(200000);
});

it('sells 2,5 L exactly, opens the drum, freezes the cost on the line (3, 11)', function () {
    $drum = ($this->receive)(208, 832000); // 40,00 MAD/L
    $sale = ($this->sell)([['product_id' => $this->bulk->id, 'oil_drum_id' => $drum->id, 'litres' => '2,5']]);
    $line = $sale->lines->first();

    expect($line->quantity_ml)->toBe(2500)
        ->and($line->total_ttc_cents)->toBe(15000)          // 2,5 × 60
        ->and($line->unit_cost_cents)->toBe(10000)          // 2,5 × 40
        ->and($sale->marginCents())->toBe(12500 - 10000)
        ->and($drum->fresh()->remaining_ml)->toBe(205500)
        ->and($drum->fresh()->status)->toBe(OilDrumStatus::Open);

    // A later drum at another price never changes this invoice.
    ($this->receive)(208, 1040000);
    expect($sale->fresh()->lines->first()->unit_cost_cents)->toBe(10000);
});

it('sells the same oil from two different drums (4)', function () {
    $a = ($this->receive)();
    $b = ($this->receive)();
    ($this->sell)([
        ['product_id' => $this->bulk->id, 'oil_drum_id' => $a->id, 'litres' => '4'],
        ['product_id' => $this->bulk->id, 'oil_drum_id' => $b->id, 'litres' => '1.25'],
    ]);

    expect($a->fresh()->remaining_ml)->toBe(204000)->and($b->fresh()->remaining_ml)->toBe(206750);
});

it('refuses more than what is left, or a drum of another site (5)', function () {
    $drum = ($this->receive)(3, 12000);
    expect(fn () => ($this->sell)([['product_id' => $this->bulk->id, 'oil_drum_id' => $drum->id, 'litres' => '3,5']]))
        ->toThrow(BusinessRuleException::class);

    $other = ($this->receive)(208, 832000, 0, null, 'M02');
    expect(fn () => ($this->sell)([['product_id' => $this->bulk->id, 'oil_drum_id' => $other->id, 'litres' => '1']]))
        ->toThrow(BusinessRuleException::class, __('oil_drums.errors.other_site', ['drum' => $other->code]));
    expect($drum->fresh()->remaining_ml)->toBe(3000);
});

it('sells sealed cans without touching the drums (6)', function () {
    $drum = ($this->receive)();
    $can = Product::factory()->create(['name' => 'Huile 10W40 5L', 'unit' => Unit::Can, 'price_retail_cents' => 30000]);
    app(StockService::class)->receive($can, site('M01'), 5, 20000);
    ($this->sell)([['product_id' => $can->id, 'quantity' => 2]]);

    expect($drum->fresh()->remaining_ml)->toBe(208000)
        ->and(StockLevel::query()->where('product_id', $can->id)->value('quantity'))->toBe(3)
        ->and(fn () => app(StockService::class)->receive($this->bulk, site('M01'), 1, 100))->toThrow(BusinessRuleException::class);
});

it('counts the oil of an oil change in the same invoice as the service (7)', function () {
    $drum = ($this->receive)();
    $service = Product::factory()->service()->create(['name' => 'Vidange', 'price_retail_cents' => 6000]);
    $sale = ($this->sell)([
        ['product_id' => $service->id, 'quantity' => 1],
        ['product_id' => $this->bulk->id, 'oil_drum_id' => $drum->id, 'litres' => '4,2'],
    ]);

    expect($sale->workOrder)->not->toBeNull()
        ->and($sale->lines->firstWhere('kind', 'bulk_oil')->unit_cost_cents)->toBe(16800) // 4,2 × 40
        ->and($drum->fresh()->remaining_ml)->toBe(203800);
});

it('corrects a drum after a dipstick count, with a reason, owner or inventory approver only (8)', function () {
    $drum = ($this->receive)();
    expect(fn () => $this->drums->adjust($drum, -500, 'Jauge', $this->seller))->toThrow(BusinessRuleException::class);
    expect(fn () => $this->drums->adjust($drum, -500, '', $this->owner))->toThrow(BusinessRuleException::class);

    $m = $this->drums->adjust($drum, -1500, 'Jauge : fuite au robinet', $this->owner);
    expect($m->reason)->toBe('Jauge : fuite au robinet')->and($drum->fresh()->remaining_ml)->toBe(206500);
});

it('transfers a drum to another site with two ledger lines (9)', function () {
    $drum = ($this->receive)(208, 832000, 0, null, 'DEP');
    $this->drums->transfer($drum, site('M02'), $this->owner);

    expect($drum->fresh()->site_id)->toBe(site('M02')->id)
        ->and(OilDrumMovement::query()->where('oil_drum_id', $drum->id)->where('site_id', site('M02')->id)->sum('quantity_ml'))->toEqual(208000)
        ->and(OilDrumMovement::query()->where('oil_drum_id', $drum->id)->where('site_id', site('DEP')->id)->sum('quantity_ml'))->toEqual(0);
});

it('serialises two sales on the same drum: the second sees what the first took (10)', function () {
    $drum = ($this->receive)(5, 20000);
    // Both drafts are prepared while 5 L are available; confirmation re-reads the drum under lock.
    $make = fn () => app(SaveSaleDraftAction::class)->execute(['site_id' => site('M01')->id,
        'lines' => [['product_id' => $this->bulk->id, 'oil_drum_id' => $drum->id, 'litres' => '3']]], $this->seller);
    [$first, $second] = [$make(), $make()];

    app(ConfirmSaleAction::class)->execute($first, [['method' => 'cash', 'amount_cents' => $first->total_ttc_cents]], $this->seller);
    expect(fn () => app(ConfirmSaleAction::class)->execute($second, [['method' => 'cash', 'amount_cents' => $second->total_ttc_cents]], $this->seller))
        ->toThrow(BusinessRuleException::class);
    expect($drum->fresh()->remaining_ml)->toBe(2000);
});

it('keeps stock:verify clean after sale, return and correction; the ledger is append-only (12)', function () {
    $drum = ($this->receive)();
    $sale = ($this->sell)([['product_id' => $this->bulk->id, 'oil_drum_id' => $drum->id, 'litres' => '5']]);
    app(CreateCreditNoteAction::class)->request($sale, [$sale->lines->first()->id => ['quantity' => 1, 'restock' => true]], RefundMethod::Cash, 'Erreur', $this->owner);
    $this->drums->adjust($drum, -200, 'Jauge', $this->owner);

    expect($drum->fresh()->remaining_ml)->toBe(207800)
        ->and(app(StockVerifier::class)->differences())->toBe([]);

    expect(fn () => DB::transaction(fn () => DB::table('oil_drum_movements')->where('oil_drum_id', $drum->id)->update(['quantity_ml' => 1])))
        ->toThrow(QueryException::class);
});
