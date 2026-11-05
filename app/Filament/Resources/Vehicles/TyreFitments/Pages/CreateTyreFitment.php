<?php

namespace App\Filament\Resources\Vehicles\TyreFitments\Pages;

use App\Enums\CompatibilityStatus;
use App\Filament\Resources\Vehicles\TyreFitments\TyreFitmentResource;
use Filament\Resources\Pages\CreateRecord;

/** A manual entry starts "to check": verification is a separate, journalled action. */
class CreateTyreFitment extends CreateRecord
{
    protected static string $resource = TyreFitmentResource::class;

    protected function mutateFormDataBeforeCreate(array $data): array
    {
        return [...$data, 'status' => CompatibilityStatus::ToCheck];
    }

    protected function afterCreate(): void
    {
        $this->record->forceFill(['created_by' => auth()->id()])->saveQuietly();
    }

    protected function getRedirectUrl(): string
    {
        return TyreFitmentResource::getUrl('view', ['record' => $this->record]);
    }
}
