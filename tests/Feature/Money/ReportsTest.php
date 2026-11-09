<?php

use App\Actions\Sales\ConfirmSaleAction;
use App\Actions\Sales\SaveSaleDraftAction;
use App\Enums\RoleName;
use App\Enums\StockMovementType;
use App\Filament\Pages\Reports;
use App\Models\Customer;
use App\Models\Product;
use App\Services\Reports\SalesReport;
use App\Services\Stock\StockService;
use App\Support\Money;
use Livewire\Livewire;

beforeEach(function () {
    $this->owner = owner();
    $this->seller = userWithRole(RoleName::Seller, 'M01');
    $this->tyre = Product::factory()->tyre()->create(['price_retail_cents' => 120000]);
    app(StockService::class)->receive($this->tyre, site('M01'), 10, 50000);
    $this->noCost = Product::factory()->create(['name' => 'Sans coût', 'price_retail_cents' => 12000]);
    app(StockService::class)->receive($this->noCost, site('M02'), 5, null, StockMovementType::OpeningStock);
    $this->pro = Customer::factory()->company()->create(['name' => 'Atlas']);
    $d = app(SaveSaleDraftAction::class)->execute(['site_id' => site('M01')->id, 'customer_id' => $this->pro->id, 'lines' => [['product_id' => $this->tyre->id, 'quantity' => 2]]], $this->seller);
    app(ConfirmSaleAction::class)->execute($d, [['method' => 'cash', 'amount_cents' => 100000]], $this->owner);
});

it('summarises sales, groups by site / seller / category, top products, aged debts and stock value', function () {
    $r = new SalesReport(now()->toImmutable()->startOfMonth(), now()->toImmutable(), null, $this->owner);

    expect((int) $r->summary()->ttc)->toBe(240000)
        ->and((int) $r->summary()->margin)->toBe(200000 - 100000)
        ->and($r->by('site')->first()->label)->toBe(site('M01')->name)
        ->and($r->by('seller')->first()->label)->toBe($this->seller->name)
        ->and($r->topProducts()->first()->label)->toBe($this->tyre->name)
        ->and($r->agedBalance()->first()->b0)->toBe(140000)
        ->and($r->stockValue()->firstWhere('label', site('M02')->name)->unknown)->toEqual(5);
});

it('shows the reports page to the owner and to site managers on their sites, with costs hidden from managers', function () {
    $this->actingAs($this->owner);
    Livewire::test(Reports::class)->assertSee(Money::format(240000))->assertSee(__('reports.aged'))->assertSee('Atlas')
        ->call('period', 'last_month')->assertDontSee(Money::format(240000));

    $this->actingAs(userWithRole(RoleName::SiteManager, 'M02'));
    Livewire::test(Reports::class)->assertDontSee(Money::format(240000))->assertDontSee(__('reports.stock'));
    $this->actingAs($this->seller)->get(Reports::getUrl())->assertForbidden();
});
