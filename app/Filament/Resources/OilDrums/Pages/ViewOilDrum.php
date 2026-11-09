<?php

namespace App\Filament\Resources\OilDrums\Pages;

use App\Filament\Resources\OilDrums\OilDrumResource;
use Filament\Resources\Pages\ViewRecord;
use Illuminate\Database\Eloquent\Model;

class ViewOilDrum extends ViewRecord
{
    protected static string $resource = OilDrumResource::class;

    protected function resolveRecord(int|string $key): Model
    {
        return parent::resolveRecord($key)->load(['product', 'site', 'movements']);
    }

    protected function getHeaderActions(): array
    {
        return OilDrumResource::drumActions();
    }
}
