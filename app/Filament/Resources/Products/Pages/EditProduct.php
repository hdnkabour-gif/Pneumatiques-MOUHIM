<?php

namespace App\Filament\Resources\Products\Pages;

use App\Filament\Actions\StockActions;
use App\Filament\Resources\Products\Pages\Concerns\BuildsTyreName;
use App\Filament\Resources\Products\ProductResource;
use Filament\Resources\Pages\EditRecord;

class EditProduct extends EditRecord
{
    use BuildsTyreName;

    protected static string $resource = ProductResource::class;

    protected function getHeaderActions(): array
    {
        return [StockActions::receive(), StockActions::adjust()];
    }

    protected function mutateFormDataBeforeSave(array $data): array
    {
        return $this->withProductDefaults($data);
    }
}
