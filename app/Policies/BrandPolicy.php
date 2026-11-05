<?php

namespace App\Policies;

use App\Enums\Permission;
use App\Models\Brand;
use App\Models\User;

class BrandPolicy
{
    public function viewAny(User $user): bool
    {
        return $user->can(Permission::ProductsView->value);
    }

    public function view(User $user, Brand $record): bool
    {
        return $this->viewAny($user);
    }

    public function create(User $user): bool
    {
        return $user->can(Permission::ProductsManage->value);
    }

    public function update(User $user, Brand $record): bool
    {
        return $user->can(Permission::ProductsManage->value);
    }

    /** History is kept: records are deactivated or cancelled, never deleted. */
    public function delete(User $user, Brand $record): bool
    {
        return false;
    }

    public function deleteAny(User $user): bool
    {
        return false;
    }
}
