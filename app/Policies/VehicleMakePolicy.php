<?php

namespace App\Policies;

use App\Enums\Permission;
use App\Models\User;
use App\Models\VehicleMake;

class VehicleMakePolicy
{
    public function viewAny(User $user): bool
    {
        return $user->canAny([Permission::CompatibilitiesView->value, Permission::CompatibilitiesManage->value, Permission::CompatibilitiesVerify->value]);
    }

    public function view(User $user, VehicleMake $record): bool
    {
        return $this->viewAny($user);
    }

    public function create(User $user): bool
    {
        return $user->can(Permission::CompatibilitiesManage->value);
    }

    public function update(User $user, VehicleMake $record): bool
    {
        return $user->can(Permission::CompatibilitiesManage->value);
    }

    /** History is kept: mark obsolete / inactive instead of deleting. */
    public function delete(User $user, VehicleMake $record): bool
    {
        return false;
    }

    public function deleteAny(User $user): bool
    {
        return false;
    }
}
