<?php

namespace App\Filament\Resources\Vehicles\TyreFitments\Pages;

use App\Enums\CompatibilityStatus;
use App\Filament\Resources\Vehicles\TyreFitments\TyreFitmentResource;
use Filament\Resources\Pages\EditRecord;

/** Editing a verified compatibility sends it back to "to check" (it must be re-verified). */
class EditTyreFitment extends EditRecord
{
    protected static string $resource = TyreFitmentResource::class;

    protected function afterSave(): void
    {
        if ($this->record->status === CompatibilityStatus::Verified && $this->record->wasChanged()) {
            $this->record->forceFill(['status' => CompatibilityStatus::ToCheck, 'verified_by' => null, 'verified_at' => null])->save();
        }
    }
}
