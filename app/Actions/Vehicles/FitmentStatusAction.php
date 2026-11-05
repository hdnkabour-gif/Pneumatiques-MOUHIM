<?php

namespace App\Actions\Vehicles;

use App\Enums\CompatibilityStatus;
use App\Enums\Permission;
use App\Exceptions\BusinessRuleException;
use App\Models\OilFitment;
use App\Models\TyreFitment;
use App\Models\User;

/**
 * Changes the status of a compatibility. Verification needs the dedicated
 * permission and a source (D-24); every change is journalled.
 */
class FitmentStatusAction
{
    public function execute(TyreFitment|OilFitment $fitment, CompatibilityStatus $status, User $user, ?string $note = null): void
    {
        $permission = $status === CompatibilityStatus::Verified ? Permission::CompatibilitiesVerify : Permission::CompatibilitiesManage;
        if (! $user->can($permission->value)) {
            throw BusinessRuleException::make('app.errors.forbidden');
        }

        if ($status === CompatibilityStatus::Verified && $fitment->source_id === null) {
            throw BusinessRuleException::make('vehicles.errors.source_required');
        }

        $fitment->status = $status;
        $fitment->verified_by = $status === CompatibilityStatus::Verified ? $user->id : null;
        $fitment->verified_at = $status === CompatibilityStatus::Verified ? now() : null;
        if (filled($note)) {
            $fitment->notes = trim(($fitment->notes ? $fitment->notes."\n" : '').now()->format('d/m/Y').' – '.$user->name.' : '.$note);
        }
        $fitment->save();
    }
}
