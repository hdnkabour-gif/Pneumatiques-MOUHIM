<?php

namespace App\Filament\Resources\Vehicles\TyreFitments\Pages;

use App\Filament\Actions\ImportVehicleWorkbookAction;
use App\Filament\Resources\Vehicles\TyreFitments\TyreFitmentResource;
use Filament\Actions\CreateAction;
use Filament\Resources\Pages\ListRecords;

class ListTyreFitments extends ListRecords
{
    protected static string $resource = TyreFitmentResource::class;

    protected function getHeaderActions(): array
    {
        return [ImportVehicleWorkbookAction::make(), CreateAction::make()];
    }
}
