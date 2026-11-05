<?php

namespace App\Policies;

use App\Enums\Permission;
use App\Models\ReplenishmentRequest;
use App\Models\User;

class ReplenishmentRequestPolicy
{
    public function viewAny(User $user): bool
    {
        return $user->canAny([Permission::ReplenishmentRequest->value, Permission::TransfersSend->value]);
    }

    public function view(User $user, ReplenishmentRequest $record): bool
    {
        return $this->viewAny($user) && ($user->canAccessSite($record->site_id) || $user->canAccessSite($record->source_site_id));
    }

    public function create(User $user): bool
    {
        return $user->can(Permission::ReplenishmentRequest->value);
    }

    public function update(User $user, ReplenishmentRequest $record): bool
    {
        return $user->can(Permission::ReplenishmentRequest->value);
    }

    /** History is kept: records are deactivated or cancelled, never deleted. */
    public function delete(User $user, ReplenishmentRequest $record): bool
    {
        return false;
    }

    public function deleteAny(User $user): bool
    {
        return false;
    }
}
