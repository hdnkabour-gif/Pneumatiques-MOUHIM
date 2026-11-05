<?php

namespace App\Filament\Resources\Vehicles\OilFitments\Pages;

use App\Enums\CompatibilityStatus;
use App\Filament\Resources\Vehicles\OilFitments\OilFitmentResource;
use Filament\Resources\Pages\EditRecord;

/** Editing a verified compatibility sends it back to "to check" (it must be re-verified). */
class EditOilFitment extends EditRecord
{
    protected static string $resource = OilFitmentResource::class;

    protected function afterSave(): void
    {
        if ($this->record->status === CompatibilityStatus::Verified && $this->record->wasChanged()) {
            $this->record->forceFill(['status' => CompatibilityStatus::ToCheck, 'verified_by' => null, 'verified_at' => null])->save();
        }
    }
}
