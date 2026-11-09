<?php

namespace App\Filament\Resources\Equipment\Pages;

use App\Actions\Equipment\EquipmentActions;
use App\Filament\Resources\Equipment\EquipmentResource;
use Filament\Resources\Pages\CreateRecord;
use Illuminate\Database\Eloquent\Model;

class CreateEquipment extends CreateRecord
{
    protected static string $resource = EquipmentResource::class;

    protected function handleRecordCreation(array $data): Model
    {
        return app(EquipmentActions::class)->create($data, auth()->user());
    }
}
