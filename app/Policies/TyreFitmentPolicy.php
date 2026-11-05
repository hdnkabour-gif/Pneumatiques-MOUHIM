<?php

namespace App\Policies;

use App\Enums\CompatibilityStatus;
use App\Enums\Permission;
use App\Models\TyreFitment;
use App\Models\User;

class TyreFitmentPolicy
{
    public function viewAny(User $user): bool
    {
        return $user->canAny([Permission::CompatibilitiesView->value, Permission::CompatibilitiesManage->value, Permission::CompatibilitiesVerify->value]);
    }

    public function view(User $user, TyreFitment $record): bool
    {
        return $this->viewAny($user) && ($record->status === CompatibilityStatus::Verified || $user->canAny([Permission::CompatibilitiesManage->value, Permission::CompatibilitiesVerify->value]));
    }

    public function create(User $user): bool
    {
        return $user->can(Permission::CompatibilitiesManage->value);
    }

    public function update(User $user, TyreFitment $record): bool
    {
        return $user->can(Permission::CompatibilitiesManage->value);
    }

    /** History is kept: mark obsolete / inactive instead of deleting. */
    public function delete(User $user, TyreFitment $record): bool
    {
        return false;
    }

    public function deleteAny(User $user): bool
    {
        return false;
    }
}
