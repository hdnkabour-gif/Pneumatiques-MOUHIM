<?php

namespace App\Policies;

use App\Enums\Permission;
use App\Models\Product;
use App\Models\User;

class ProductPolicy
{
    public function viewAny(User $user): bool
    {
        return $user->can(Permission::ProductsView->value);
    }

    public function view(User $user, Product $record): bool
    {
        return $this->viewAny($user);
    }

    public function create(User $user): bool
    {
        return $user->can(Permission::ProductsManage->value);
    }

    public function update(User $user, Product $record): bool
    {
        return $user->can(Permission::ProductsManage->value);
    }

    /** History is kept: records are deactivated or cancelled, never deleted. */
    public function delete(User $user, Product $record): bool
    {
        return false;
    }

    public function deleteAny(User $user): bool
    {
        return false;
    }
}
