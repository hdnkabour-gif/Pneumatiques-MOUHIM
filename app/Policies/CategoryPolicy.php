<?php

namespace App\Policies;

use App\Enums\Permission;
use App\Models\Category;
use App\Models\User;

class CategoryPolicy
{
    public function viewAny(User $user): bool
    {
        return $user->can(Permission::ProductsView->value);
    }

    public function view(User $user, Category $record): bool
    {
        return $this->viewAny($user);
    }

    public function create(User $user): bool
    {
        return $user->can(Permission::ProductsManage->value);
    }

    public function update(User $user, Category $record): bool
    {
        return $user->can(Permission::ProductsManage->value);
    }

    /** History is kept: records are deactivated or cancelled, never deleted. */
    public function delete(User $user, Category $record): bool
    {
        return false;
    }

    public function deleteAny(User $user): bool
    {
        return false;
    }
}
