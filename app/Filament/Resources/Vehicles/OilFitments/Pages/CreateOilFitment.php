<?php

namespace App\Filament\Resources\Vehicles\OilFitments\Pages;

use App\Enums\CompatibilityStatus;
use App\Filament\Resources\Vehicles\OilFitments\OilFitmentResource;
use Filament\Resources\Pages\CreateRecord;

/** A manual entry starts "to check": verification is a separate, journalled action. */
class CreateOilFitment extends CreateRecord
{
    protected static string $resource = OilFitmentResource::class;

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
        return OilFitmentResource::getUrl('view', ['record' => $this->record]);
    }
}
