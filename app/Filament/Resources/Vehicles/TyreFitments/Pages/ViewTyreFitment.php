<?php

namespace App\Filament\Resources\Vehicles\TyreFitments\Pages;

use App\Filament\Actions\FitmentActions;
use App\Filament\Resources\Vehicles\TyreFitments\TyreFitmentResource;
use Filament\Actions\EditAction;
use Filament\Resources\Pages\ViewRecord;

class ViewTyreFitment extends ViewRecord
{
    protected static string $resource = TyreFitmentResource::class;

    protected function getHeaderActions(): array
    {
        return [...FitmentActions::all(), EditAction::make()];
    }
}
