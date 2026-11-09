<?php

use App\Actions\UsedTyres\UsedTyreActions;
use App\Enums\RoleName;
use App\Enums\UsedTyreCheck;
use App\Enums\UsedTyreOrigin;
use App\Enums\UsedTyreStatus;
use App\Exceptions\BusinessRuleException;
use App\Models\StockMovement;
use App\Models\UsedTyre;
use App\Services\Stock\StockVerifier;
use App\Services\Stock\UsedTyreSetFinder;

function allChecksOk(): array
{
    return collect(UsedTyreCheck::cases())->mapWithKeys(fn ($c) => [$c->value => true])->all();
}

beforeEach(function () {
    $this->seller = userWithRole(RoleName::Seller, 'M01');
    $this->actions = app(UsedTyreActions::class);
    $this->register = fn (array $attrs = []) => $this->actions->register(
        UsedTyre::factory()->make($attrs)->getAttributes(), site('M01'), UsedTyreOrigin::Supplier, $this->seller,
    );
});

it('registers a used tyre awaiting inspection, with a code and a ledger entry', function () {
    $tyre = ($this->register)();

    expect($tyre->code)->toBe('PO-000001')
        ->and($tyre->status)->toBe(UsedTyreStatus::PendingInspection)
        ->and(StockMovement::query()->where('used_tyre_id', $tyre->id)->sum('quantity'))->toEqual(1);
});

it('blocks a tyre with a bulge and hides it from sales (§21)', function () {
    $tyre = ($this->register)();
    $checks = allChecksOk();
    $checks[UsedTyreCheck::Bulges->value] = false;

    $this->actions->inspect($tyre, $checks, $this->seller, 45);

    expect($tyre->fresh()->status)->toBe(UsedTyreStatus::Unsellable)
        ->and(UsedTyre::query()->sellable()->pluck('id')->all())->not->toContain($tyre->id)
        ->and($tyre->inspections()->first()->passed)->toBeFalse();
});

it('puts a tyre on sale when every check passes', function () {
    $tyre = ($this->register)();
    $this->actions->inspect($tyre, allChecksOk(), $this->seller, 52);

    expect($tyre->fresh()->status)->toBe(UsedTyreStatus::InStock)
        ->and($tyre->fresh()->treadDepthMm())->toBe('5.2')
        ->and(UsedTyre::query()->sellable()->pluck('id')->all())->toContain($tyre->id);
});

it('requires an answer for every check', function () {
    $tyre = ($this->register)();
    $checks = allChecksOk();
    unset($checks[UsedTyreCheck::Age->value]);

    $this->actions->inspect($tyre, $checks, $this->seller);
})->throws(BusinessRuleException::class);

it('never inspects an unsellable tyre back into stock', function () {
    $tyre = ($this->register)();
    $checks = allChecksOk();
    $checks[UsedTyreCheck::Cracks->value] = false;
    $this->actions->inspect($tyre, $checks, $this->seller);

    $this->actions->inspect($tyre->fresh(), allChecksOk(), $this->seller);
})->throws(BusinessRuleException::class);

it('scraps a tyre with a ledger exit', function () {
    $tyre = ($this->register)();
    $this->actions->scrap($tyre, $this->seller, 'Flanc déchiré');

    expect($tyre->fresh()->status)->toBe(UsedTyreStatus::Scrapped)
        ->and(StockMovement::query()->where('used_tyre_id', $tyre->id)->sum('quantity'))->toEqual(0);
});

it('finds matching sets within 1 mm of tread (D-43)', function () {
    foreach ([50, 52, 55, 58, 60, 80] as $depth) {
        $tyre = ($this->register)(['tread_depth_tenths' => $depth]);
        $this->actions->inspect($tyre, allChecksOk(), $this->seller, $depth);
    }
    // Different pattern: never in the same set.
    $other = ($this->register)(['pattern' => 'Turanza', 'tread_depth_tenths' => 51]);
    $this->actions->inspect($other, allChecksOk(), $this->seller, 51);

    $fours = app(UsedTyreSetFinder::class)->find(4);
    $pairs = app(UsedTyreSetFinder::class)->find(2);

    // 50, 52, 55, 58 span 0.8 mm → one set of four; 60 and 80 are too far apart.
    expect($fours->map(fn ($s) => $s->pluck('tread_depth_tenths')->all())->all())->toBe([[50, 52, 55, 58]]);
    expect($pairs->map(fn ($s) => $s->pluck('tread_depth_tenths')->all())->all())->toBe([[50, 52], [55, 58]]);
});

it('shows matching sets on the sets screen', function () {
    foreach ([50, 52, 55, 58] as $depth) {
        $tyre = ($this->register)(['tread_depth_tenths' => $depth]);
        $this->actions->inspect($tyre, allChecksOk(), $this->seller, $depth);
    }

    $this->actingAs($this->seller)->get('/admin/used-tyres/sets')
        ->assertOk()->assertSee('PO-000001')->assertSee('5.0 / 5.2 / 5.5 / 5.8');
});

it('checks every used tyre against its ledger in stock:verify (audit)', function () {
    $tyre = ($this->register)();
    expect(app(StockVerifier::class)->differences())->toBe([]);

    // A status changed without its movement is reported.
    $tyre->forceFill(['status' => UsedTyreStatus::Sold])->save();
    $diff = app(StockVerifier::class)->differences();
    expect($diff)->toHaveCount(1)->and($diff[0]['kind'])->toStartWith('used_tyre:'.$tyre->id);
});
