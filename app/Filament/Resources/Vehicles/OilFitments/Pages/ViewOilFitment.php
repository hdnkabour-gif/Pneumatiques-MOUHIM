<?php

namespace App\Filament\Resources\Vehicles\OilFitments\Pages;

use App\Filament\Actions\FitmentActions;
use App\Filament\Resources\Vehicles\OilFitments\OilFitmentResource;
use Filament\Actions\EditAction;
use Filament\Resources\Pages\ViewRecord;

class ViewOilFitment extends ViewRecord
{
    protected static string $resource = OilFitmentResource::class;

    protected function getHeaderActions(): array
    {
        return [...FitmentActions::all(), EditAction::make()];
    }
}
