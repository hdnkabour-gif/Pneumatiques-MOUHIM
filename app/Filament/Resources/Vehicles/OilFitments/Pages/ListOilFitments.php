<?php

namespace App\Filament\Resources\Vehicles\OilFitments\Pages;

use App\Filament\Actions\ImportVehicleWorkbookAction;
use App\Filament\Resources\Vehicles\OilFitments\OilFitmentResource;
use Filament\Actions\CreateAction;
use Filament\Resources\Pages\ListRecords;

class ListOilFitments extends ListRecords
{
    protected static string $resource = OilFitmentResource::class;

    protected function getHeaderActions(): array
    {
        return [ImportVehicleWorkbookAction::make(), CreateAction::make()];
    }
}
