<?php

namespace App\Policies;

use App\Enums\Permission;
use App\Models\UsedTyre;
use App\Models\User;

class UsedTyrePolicy
{
    public function viewAny(User $user): bool
    {
        return $user->canAny([Permission::UsedTyresManage->value, Permission::UsedTyresInspect->value]);
    }

    public function view(User $user, UsedTyre $record): bool
    {
        return $this->viewAny($user) && $user->canAccessSite($record->site_id);
    }

    public function create(User $user): bool
    {
        return $user->can(Permission::UsedTyresManage->value);
    }

    public function update(User $user, UsedTyre $record): bool
    {
        return $user->can(Permission::UsedTyresManage->value) && $user->canAccessSite($record->site_id);
    }

    /** History is kept: records are deactivated or cancelled, never deleted. */
    public function delete(User $user, UsedTyre $record): bool
    {
        return false;
    }

    public function deleteAny(User $user): bool
    {
        return false;
    }
}
