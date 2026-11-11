<?php

use App\Enums\RoleName;
use App\Filament\Pages\Reports;
use App\Filament\Pages\WhatRemains;
use App\Filament\Resources\Products\ProductResource;
use App\Filament\Resources\ReplenishmentRequests\ReplenishmentRequestResource;
use App\Filament\Resources\StockLevels\StockLevelResource;
use App\Filament\Resources\StockMovements\StockMovementResource;
use App\Filament\Resources\Suppliers\SupplierResource;
use App\Filament\Resources\Transfers\TransferResource;
use App\Filament\Resources\Users\UserResource;
use App\Filament\Support\PageHub;

it('takes secondary pages out of the owner menu', function () {
    $this->actingAs(owner());

    foreach ([StockMovementResource::class, SupplierResource::class, UserResource::class, WhatRemains::class, Reports::class] as $child) {
        expect($child::shouldRegisterNavigation())->toBeFalse($child);
    }
    foreach ([StockLevelResource::class, TransferResource::class, ProductResource::class] as $main) {
        expect($main::shouldRegisterNavigation())->toBeTrue($main);
    }
});

it('keeps a secondary page in the menu when its main page is not allowed', function () {
    $this->actingAs($seller = userWithRole(RoleName::Seller, 'M01'));

    expect(TransferResource::canAccess())->toBeFalse()
        ->and(ReplenishmentRequestResource::shouldRegisterNavigation())->toBeTrue();
});

it('lists only the secondary pages the user may open', function () {
    $this->actingAs(owner());
    $owner = collect(PageHub::actions(StockLevelResource::class)[0]->getActions())->map->getName();

    $this->actingAs(userWithRole(RoleName::Seller, 'M01'));
    $seller = collect(PageHub::actions(StockLevelResource::class))->flatMap(fn ($g) => $g->getActions())->map->getName();

    expect($owner)->toContain('hub_StockMovementResource', 'hub_InventoryCountResource')
        ->and($seller)->not->toContain('hub_StockMovementResource');
});

it('shows the « Plus » button on the main page and the hidden pages still open', function () {
    $this->actingAs(owner());

    $this->get(StockLevelResource::getUrl())->assertOk()
        ->assertSee(__('app.more_pages'))->assertSee(StockMovementResource::getNavigationLabel());
    $this->get(StockMovementResource::getUrl())->assertOk();
    $this->get(SupplierResource::getUrl())->assertOk();
});
