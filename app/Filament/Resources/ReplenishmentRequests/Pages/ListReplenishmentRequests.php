<?php

namespace App\Filament\Resources\ReplenishmentRequests\Pages;

use App\Filament\Resources\ReplenishmentRequests\ReplenishmentRequestResource;
use Filament\Actions\CreateAction;
use Filament\Resources\Pages\ListRecords;

class ListReplenishmentRequests extends ListRecords
{
    protected static string $resource = ReplenishmentRequestResource::class;

    protected function getHeaderActions(): array
    {
        return [CreateAction::make()];
    }
}
