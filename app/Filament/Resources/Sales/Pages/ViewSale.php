<?php

namespace App\Filament\Resources\Sales\Pages;

use App\Filament\Resources\Sales\SaleResource;
use Filament\Resources\Pages\ViewRecord;

class ViewSale extends ViewRecord
{
    protected static string $resource = SaleResource::class;

    public function getTitle(): string
    {
        return $this->record->number ?? __('enums.sale_status.draft');
    }

    protected function getHeaderActions(): array
    {
        return SaleResource::documentActions();
    }
}
