<?php

namespace App\Filament\Resources\Vehicles\VehicleMakes\Pages;

use App\Filament\Actions\ImportVehicleWorkbookAction;
use App\Filament\Resources\Vehicles\VehicleMakes\VehicleMakeResource;
use Filament\Actions\CreateAction;
use Filament\Resources\Pages\ManageRecords;

class ManageVehicleMakes extends ManageRecords
{
    protected static string $resource = VehicleMakeResource::class;

    protected function getHeaderActions(): array
    {
        return [ImportVehicleWorkbookAction::make(), CreateAction::make()];
    }
}
