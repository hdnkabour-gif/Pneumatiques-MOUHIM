<?php

namespace App\Policies;

use App\Enums\Permission;
use App\Models\Customer;
use App\Models\User;

/** Customers are shared by the three sites (one company, one customer file). */
class CustomerPolicy
{
    public function viewAny(User $user): bool
    {
        return $user->canAny([Permission::CustomersView->value, Permission::CustomersManage->value]);
    }

    public function view(User $user, Customer $record): bool
    {
        return $this->viewAny($user);
    }

    public function create(User $user): bool
    {
        return $user->can(Permission::CustomersManage->value);
    }

    public function update(User $user, Customer $record): bool
    {
        return $user->can(Permission::CustomersManage->value);
    }

    /** Customers with documents are deactivated, never deleted. */
    public function delete(User $user, Customer $record): bool
    {
        return false;
    }

    public function deleteAny(User $user): bool
    {
        return false;
    }
}
