<?php

namespace App\Policies;

use App\Enums\Permission;
use App\Models\StockMovement;
use App\Models\User;

class StockMovementPolicy
{
    public function viewAny(User $user): bool
    {
        return $user->can(Permission::StockView->value);
    }

    public function view(User $user, StockMovement $record): bool
    {
        return $this->viewAny($user) && $user->canAccessSite($record->site_id);
    }

    public function create(User $user): bool
    {
        return false;
    }

    public function update(User $user, StockMovement $record): bool
    {
        return false;
    }

    /** History is kept: records are deactivated or cancelled, never deleted. */
    public function delete(User $user, StockMovement $record): bool
    {
        return false;
    }

    public function deleteAny(User $user): bool
    {
        return false;
    }
}
