<?php

namespace App\Filament\Resources\StockLevels\Pages;

use App\Filament\Resources\StockLevels\StockLevelResource;
use App\Filament\Support\PageHub;
use Filament\Resources\Pages\ListRecords;

class ListStockLevels extends ListRecords
{
    protected static string $resource = StockLevelResource::class;

    protected function getHeaderActions(): array
    {
        return PageHub::actions(StockLevelResource::class);
    }
}
