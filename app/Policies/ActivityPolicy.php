<?php

namespace App\Policies;

use App\Enums\Permission;
use App\Models\User;
use Illuminate\Database\Eloquent\Model;

/** The activity log is read-only for everyone (§16.4). */
class ActivityPolicy
{
    public function viewAny(User $user): bool
    {
        return $user->can(Permission::ActivityLogView->value);
    }

    public function view(User $user, Model $activity): bool
    {
        return $this->viewAny($user);
    }

    public function create(User $user): bool
    {
        return false;
    }

    public function update(User $user, Model $activity): bool
    {
        return false;
    }

    public function delete(User $user, Model $activity): bool
    {
        return false;
    }

    public function deleteAny(User $user): bool
    {
        return false;
    }
}
