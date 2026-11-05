<?php

namespace App\Policies;

use App\Enums\Permission;
use App\Models\Transfer;
use App\Models\User;

class TransferPolicy
{
    public function viewAny(User $user): bool
    {
        return $user->canAny([Permission::TransfersSend->value, Permission::TransfersReceive->value]);
    }

    public function view(User $user, Transfer $record): bool
    {
        return $this->viewAny($user) && ($user->canAccessSite($record->from_site_id) || $user->canAccessSite($record->to_site_id));
    }

    public function create(User $user): bool
    {
        return $user->can(Permission::TransfersSend->value);
    }

    public function update(User $user, Transfer $record): bool
    {
        return $user->can(Permission::TransfersSend->value);
    }

    /** History is kept: records are deactivated or cancelled, never deleted. */
    public function delete(User $user, Transfer $record): bool
    {
        return false;
    }

    public function deleteAny(User $user): bool
    {
        return false;
    }
}
