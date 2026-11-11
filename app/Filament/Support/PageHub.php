<?php

namespace App\Filament\Support;

use App\Filament\Pages\AccountingExportPage;
use App\Filament\Pages\CashDesk;
use App\Filament\Pages\CompanySettings;
use App\Filament\Pages\DocumentNumbering;
use App\Filament\Pages\Reports;
use App\Filament\Pages\StartupImports;
use App\Filament\Pages\Today;
use App\Filament\Pages\VehicleLookup;
use App\Filament\Pages\WhatRemains;
use App\Filament\Pages\Workshop;
use App\Filament\Resources\Activities\ActivityResource;
use App\Filament\Resources\Brands\BrandResource;
use App\Filament\Resources\Bundles\BundleResource;
use App\Filament\Resources\Categories\CategoryResource;
use App\Filament\Resources\Equipment\EquipmentResource;
use App\Filament\Resources\InventoryCounts\InventoryCountResource;
use App\Filament\Resources\OilCatalogueReferences\OilCatalogueReferenceResource;
use App\Filament\Resources\OilDrums\OilDrumResource;
use App\Filament\Resources\Payments\PaymentResource;
use App\Filament\Resources\Products\ProductResource;
use App\Filament\Resources\PurchaseOrders\PurchaseOrderResource;
use App\Filament\Resources\ReplenishmentRequests\ReplenishmentRequestResource;
use App\Filament\Resources\Roles\RoleResource;
use App\Filament\Resources\Services\ServiceResource;
use App\Filament\Resources\Sites\SiteResource;
use App\Filament\Resources\StockLevels\StockLevelResource;
use App\Filament\Resources\StockMovements\StockMovementResource;
use App\Filament\Resources\Suppliers\SupplierResource;
use App\Filament\Resources\Transfers\TransferResource;
use App\Filament\Resources\TyreCatalogueReferences\TyreCatalogueReferenceResource;
use App\Filament\Resources\TyreLots\TyreLotResource;
use App\Filament\Resources\Users\UserResource;
use App\Filament\Resources\Vehicles\CompatibilitySources\CompatibilitySourceResource;
use App\Filament\Resources\Vehicles\OilFitments\OilFitmentResource;
use App\Filament\Resources\Vehicles\TyreFitments\TyreFitmentResource;
use App\Filament\Resources\Vehicles\VehicleEngines\VehicleEngineResource;
use App\Filament\Resources\Vehicles\VehicleGenerations\VehicleGenerationResource;
use App\Filament\Resources\Vehicles\VehicleMakes\VehicleMakeResource;
use App\Filament\Resources\Vehicles\VehicleModels\VehicleModelResource;
use Filament\Actions\Action;
use Filament\Actions\ActionGroup;
use Filament\Support\Icons\Heroicon;

/**
 * Groups secondary pages under one main page (D-57): the secondary page leaves the
 * menu and becomes an entry of the « Plus » button on its main page. Nothing is
 * removed: URLs, policies and permissions stay exactly the same. When the user
 * cannot open the main page, the secondary page stays in the menu as before.
 */
final class PageHub
{
    /** main page => secondary pages, in display order */
    public const HUBS = [
        Today::class => [WhatRemains::class, Reports::class],
        CashDesk::class => [PaymentResource::class],
        Workshop::class => [EquipmentResource::class],
        StockLevelResource::class => [StockMovementResource::class, TyreLotResource::class, OilDrumResource::class, InventoryCountResource::class],
        TransferResource::class => [ReplenishmentRequestResource::class],
        PurchaseOrderResource::class => [SupplierResource::class],
        ProductResource::class => [CategoryResource::class, BrandResource::class, ServiceResource::class, BundleResource::class,
            TyreCatalogueReferenceResource::class, OilCatalogueReferenceResource::class],
        VehicleLookup::class => [VehicleMakeResource::class, VehicleModelResource::class, VehicleGenerationResource::class,
            VehicleEngineResource::class, TyreFitmentResource::class, OilFitmentResource::class, CompatibilitySourceResource::class],
        CompanySettings::class => [DocumentNumbering::class, SiteResource::class, UserResource::class, RoleResource::class,
            StartupImports::class, AccountingExportPage::class, ActivityResource::class],
    ];

    /** The main page holding this page, or null when it has none. */
    public static function parentOf(string $class): ?string
    {
        foreach (self::HUBS as $parent => $children) {
            if (in_array($class, $children, true)) {
                return $parent;
            }
        }

        return null;
    }

    /** False when the page is reachable from its main page's « Plus » button. */
    public static function inMenu(string $class): bool
    {
        if (self::$probing) {
            return true;
        }
        $parent = self::parentOf($class);

        return $parent === null || ! self::canOpen($parent);
    }

    private static bool $probing = false;

    /** Allowed AND shown to this user by the page's own menu rules (permissions), ignoring the grouping. */
    public static function canOpen(string $class): bool
    {
        if (! auth()->check() || ! $class::canAccess()) {
            return false;
        }
        $was = self::$probing;
        self::$probing = true;
        try {
            return $class::shouldRegisterNavigation();
        } finally {
            self::$probing = $was;
        }
    }

    /** @return array<int, ActionGroup> the « Plus » button for a main page (empty when nothing to show) */
    public static function actions(string $parent): array
    {
        $links = collect(self::HUBS[$parent] ?? [])
            ->filter(fn (string $child) => self::canOpen($child))
            ->map(fn (string $child) => Action::make('hub_'.class_basename($child))
                ->label($child::getNavigationLabel())
                ->icon($child::getNavigationIcon())
                ->url($child::getUrl()))
            ->values()->all();

        return $links === [] ? [] : [
            ActionGroup::make($links)->label(__('app.more_pages'))->icon(Heroicon::OutlinedSquares2x2)
                ->button()->color('gray'),
        ];
    }
}
