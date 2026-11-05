<?php

namespace App\Filament\Resources\Vehicles\VehicleGenerations\Pages;

use App\Filament\Resources\Vehicles\VehicleGenerations\VehicleGenerationResource;
use Filament\Actions\CreateAction;
use Filament\Resources\Pages\ManageRecords;

class ManageVehicleGenerations extends ManageRecords
{
    protected static string $resource = VehicleGenerationResource::class;

    protected function getHeaderActions(): array
    {
        return [CreateAction::make()];
    }
}
