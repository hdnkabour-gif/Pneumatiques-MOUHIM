<?php

namespace App\Policies;

use App\Enums\Permission;
use App\Models\InventoryCount;
use App\Models\User;

class InventoryCountPolicy
{
    public function viewAny(User $user): bool
    {
        return $user->canAny([Permission::InventoryCount->value, Permission::InventoryApprove->value]);
    }

    public function view(User $user, InventoryCount $record): bool
    {
        return $this->viewAny($user) && $user->canAccessSite($record->site_id);
    }

    public function create(User $user): bool
    {
        return $user->can(Permission::InventoryCount->value);
    }

    public function update(User $user, InventoryCount $record): bool
    {
        return $user->can(Permission::InventoryCount->value);
    }

    /** History is kept: records are deactivated or cancelled, never deleted. */
    public function delete(User $user, InventoryCount $record): bool
    {
        return false;
    }

    public function deleteAny(User $user): bool
    {
        return false;
    }
}
