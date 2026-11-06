<?php

namespace App\Filament\Resources\Sales\Pages;

use App\Filament\Resources\Sales\SaleResource;
use Filament\Resources\Pages\ViewRecord;
use Illuminate\Database\Eloquent\Model;

class ViewSale extends ViewRecord
{
    protected static string $resource = SaleResource::class;

    protected function resolveRecord(int|string $key): Model
    {
        return parent::resolveRecord($key)->load(['lines', 'payments', 'vehicle', 'originalSale', 'recapInvoice', 'creditNotes']);
    }

    public function getTitle(): string
    {
        return $this->record->number ?? __('enums.sale_status.draft');
    }

    protected function getHeaderActions(): array
    {
        return SaleResource::documentActions();
    }
}
