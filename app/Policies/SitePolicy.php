<?php

namespace App\Policies;

use App\Enums\Permission;
use App\Models\Site;
use App\Models\User;

class SitePolicy
{
    public function viewAny(User $user): bool
    {
        return $user->can(Permission::SitesView->value) || $user->can(Permission::SitesViewAll->value);
    }

    public function view(User $user, Site $site): bool
    {
        return $this->viewAny($user) && $user->canAccessSite($site);
    }

    public function create(User $user): bool
    {
        return $user->can(Permission::SitesManage->value);
    }

    public function update(User $user, Site $site): bool
    {
        return $user->can(Permission::SitesManage->value);
    }

    /** Sites carry business history: deactivate, never delete. */
    public function delete(User $user, Site $site): bool
    {
        return false;
    }

    public function deleteAny(User $user): bool
    {
        return false;
    }
}
