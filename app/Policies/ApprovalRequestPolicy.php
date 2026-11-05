<?php

namespace App\Policies;

use App\Enums\Permission;
use App\Models\ApprovalRequest;
use App\Models\User;

class ApprovalRequestPolicy
{
    public function viewAny(User $user): bool
    {
        return $user->canAny([Permission::ApprovalsDecide->value, Permission::StockAdjust->value]);
    }

    public function view(User $user, ApprovalRequest $record): bool
    {
        return $this->viewAny($user) && ($user->can(Permission::ApprovalsDecide->value) || $record->requested_by === $user->id);
    }

    public function create(User $user): bool
    {
        return false;
    }

    public function update(User $user, ApprovalRequest $record): bool
    {
        return false;
    }

    /** History is kept: records are deactivated or cancelled, never deleted. */
    public function delete(User $user, ApprovalRequest $record): bool
    {
        return false;
    }

    public function deleteAny(User $user): bool
    {
        return false;
    }
}
