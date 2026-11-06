<?php

namespace App\Policies;

use App\Enums\Permission;
use App\Models\Sale;
use App\Models\User;

class SalePolicy
{
    public function viewAny(User $user): bool
    {
        return $user->canAny([Permission::SalesView->value, Permission::SalesCreate->value]);
    }

    public function view(User $user, Sale $record): bool
    {
        return $this->viewAny($user) && $user->canAccessSite($record->site_id);
    }

    /** Sales are made on the sale screen (ConfirmSaleAction), not through a form. */
    public function create(User $user): bool
    {
        return false;
    }

    public function update(User $user, Sale $record): bool
    {
        return false;
    }

    /** Only an unconfirmed draft can be thrown away; an invoice never (D-15). */
    public function delete(User $user, Sale $record): bool
    {
        return $record->isDraft() && $user->can(Permission::SalesCreate->value) && $user->canAccessSite($record->site_id);
    }

    public function deleteAny(User $user): bool
    {
        return false;
    }
}
