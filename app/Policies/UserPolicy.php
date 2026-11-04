<?php

namespace App\Policies;

use App\Enums\Permission;
use App\Models\User;

class UserPolicy
{
    public function viewAny(User $user): bool
    {
        return $user->can(Permission::UsersView->value) || $user->can(Permission::UsersManage->value);
    }

    /** Non-global users only see colleagues who share at least one of their sites. */
    public function view(User $user, User $model): bool
    {
        if (! $this->viewAny($user)) {
            return false;
        }

        return $user->canSeeAllSites()
            || $user->is($model)
            || array_intersect($user->siteIds(), $model->siteIds()) !== [];
    }

    public function create(User $user): bool
    {
        return $user->can(Permission::UsersManage->value);
    }

    /** Only an owner may modify an owner account. */
    public function update(User $user, User $model): bool
    {
        if (! $user->can(Permission::UsersManage->value)) {
            return false;
        }

        return ! $model->isOwner() || $user->isOwner();
    }

    /** Users are deactivated, never deleted (their history must stay attributable). */
    public function delete(User $user, User $model): bool
    {
        return false;
    }

    public function deleteAny(User $user): bool
    {
        return false;
    }
}
