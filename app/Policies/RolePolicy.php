<?php

namespace App\Policies;

use App\Enums\Permission;
use App\Enums\RoleName;
use App\Models\User;
use Spatie\Permission\Models\Role;

class RolePolicy
{
    public function viewAny(User $user): bool
    {
        return $user->can(Permission::RolesManage->value);
    }

    public function view(User $user, Role $role): bool
    {
        return $this->viewAny($user);
    }

    public function create(User $user): bool
    {
        return $user->can(Permission::RolesManage->value);
    }

    /** The owner role always keeps full access and cannot be edited. */
    public function update(User $user, Role $role): bool
    {
        return $user->can(Permission::RolesManage->value) && $role->name !== RoleName::Owner->value;
    }

    /** Default roles cannot be deleted; custom roles only when unused. */
    public function delete(User $user, Role $role): bool
    {
        return $user->can(Permission::RolesManage->value)
            && RoleName::tryFrom($role->name) === null
            && ! $role->users()->exists();
    }

    public function deleteAny(User $user): bool
    {
        return false;
    }
}
