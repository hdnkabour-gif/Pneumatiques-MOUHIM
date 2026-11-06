<?php

namespace App\Filament\Resources\Sales\Pages;

use App\Filament\Pages\SaleScreen;
use App\Filament\Resources\Sales\SaleResource;
use Filament\Actions\Action;
use Filament\Resources\Pages\ListRecords;
use Filament\Support\Icons\Heroicon;

class ListSales extends ListRecords
{
    protected static string $resource = SaleResource::class;

    protected function getHeaderActions(): array
    {
        return [
            Action::make('new')->label(__('sales.screen.title'))->icon(Heroicon::OutlinedShoppingCart)
                ->visible(fn () => SaleScreen::canAccess())->url(fn () => SaleScreen::getUrl()),
        ];
    }
}
