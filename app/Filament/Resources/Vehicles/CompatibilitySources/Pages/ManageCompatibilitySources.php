<?php

namespace App\Filament\Resources\Vehicles\CompatibilitySources\Pages;

use App\Filament\Resources\Vehicles\CompatibilitySources\CompatibilitySourceResource;
use Filament\Actions\CreateAction;
use Filament\Resources\Pages\ManageRecords;

class ManageCompatibilitySources extends ManageRecords
{
    protected static string $resource = CompatibilitySourceResource::class;

    protected function getHeaderActions(): array
    {
        return [CreateAction::make()];
    }
}
