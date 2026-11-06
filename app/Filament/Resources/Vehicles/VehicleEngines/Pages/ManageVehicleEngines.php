<?php

namespace App\Filament\Resources\Vehicles\VehicleEngines\Pages;

use App\Filament\Actions\ImportVehicleWorkbookAction;
use App\Filament\Resources\Vehicles\VehicleEngines\VehicleEngineResource;
use Filament\Actions\CreateAction;
use Filament\Resources\Pages\ManageRecords;

class ManageVehicleEngines extends ManageRecords
{
    protected static string $resource = VehicleEngineResource::class;

    protected function getHeaderActions(): array
    {
        return [ImportVehicleWorkbookAction::make(), CreateAction::make()];
    }
}
