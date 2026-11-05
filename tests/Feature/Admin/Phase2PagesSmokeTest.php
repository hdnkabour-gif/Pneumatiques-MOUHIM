<?php

use App\Actions\Inventory\InventoryCountActions;
use App\Actions\Stock\RequestStockAdjustmentAction;
use App\Actions\Transfers\CreateReplenishmentRequestAction;
use App\Actions\Transfers\SendTransferAction;
use App\Actions\UsedTyres\UsedTyreActions;
use App\Enums\RoleName;
use App\Enums\UsedTyreOrigin;
use App\Models\Product;
use App\Models\UsedTyre;
use App\Services\Stock\StockService;

beforeEach(function () {
    $this->owner = owner();
    $this->product = Product::factory()->tyre()->create();
    app(StockService::class)->receive($this->product, site('DEP'), 10, 40000);
    $this->transfer = app(SendTransferAction::class)->execute(site('DEP'), site('M01'), [$this->product->id => 2], $this->owner);
    $this->request = app(CreateReplenishmentRequestAction::class)->execute(site('M02'), site('DEP'), [$this->product->id => 2], $this->owner);
    $this->count = app(InventoryCountActions::class)->create(site('DEP'), $this->owner);
    $this->approval = app(RequestStockAdjustmentAction::class)->execute($this->product, site('DEP'), -1, 'Casse', userWithRole(RoleName::Storekeeper, 'DEP'), true);
    $this->tyre = app(UsedTyreActions::class)->register(UsedTyre::factory()->make()->getAttributes(), site('M01'), UsedTyreOrigin::Supplier, $this->owner);
});

it('renders every phase 2 page for the owner', function () {
    $urls = [
        '/admin/products', '/admin/products/create', "/admin/products/{$this->product->id}/edit",
        '/admin/services', '/admin/services/create', '/admin/bundles', '/admin/bundles/create',
        '/admin/categories', '/admin/brands', '/admin/stock-levels', '/admin/stock-movements', '/admin/tyre-lots',
        '/admin/transfers', '/admin/transfers/create', "/admin/transfers/{$this->transfer->id}",
        '/admin/replenishment-requests', '/admin/replenishment-requests/create', "/admin/replenishment-requests/{$this->request->id}",
        '/admin/inventory-counts', '/admin/inventory-counts/create', "/admin/inventory-counts/{$this->count->id}",
        '/admin/approval-requests', "/admin/approval-requests/{$this->approval->id}",
        '/admin/used-tyres', '/admin/used-tyres/create', '/admin/used-tyres/sets', "/admin/used-tyres/{$this->tyre->id}", "/admin/used-tyres/{$this->tyre->id}/edit",
        "/admin/print/transfers/{$this->transfer->id}", "/admin/print/used-tyres/{$this->tyre->id}/label",
    ];

    $this->actingAs($this->owner);
    foreach ($urls as $url) {
        $response = $this->get($url);
        expect($response->status())->toBe(200, "GET {$url} returned {$response->status()}");
    }
});

it('renders the main phase 2 pages in Arabic', function () {
    $this->owner->update(['locale' => 'ar']);
    $this->actingAs($this->owner);

    foreach (['/admin/products', '/admin/stock-levels', "/admin/transfers/{$this->transfer->id}", "/admin/used-tyres/{$this->tyre->id}", '/admin/approval-requests'] as $url) {
        $this->get($url)->assertOk()->assertSee('dir="rtl"', false);
    }
});
