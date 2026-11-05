<?php

namespace App\Filament\Resources\Services\Pages;

use App\Enums\ProductKind;
use App\Filament\Resources\Services\ServiceResource;
use Filament\Resources\Pages\CreateRecord;

class CreateService extends CreateRecord
{
    protected static string $resource = ServiceResource::class;

    protected function mutateFormDataBeforeCreate(array $data): array
    {
        return [...$data, 'kind' => ProductKind::Service];
    }
}
