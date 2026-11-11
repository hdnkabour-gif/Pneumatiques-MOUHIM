<?php

namespace App\Policies;

use App\Enums\Permission;
use App\Models\TyreCatalogueReference;
use App\Models\User;

/** Reference catalogue (information only): read by whoever sees compatibilities, corrected by whoever manages them. */
class TyreCatalogueReferencePolicy
{
    public function viewAny(User $user): bool
    {
        return $user->canAny([Permission::CompatibilitiesView->value, Permission::CompatibilitiesManage->value]);
    }

    public function view(User $user, TyreCatalogueReference $reference): bool
    {
        return $this->viewAny($user);
    }

    public function create(User $user): bool
    {
        return false;
    }

    public function update(User $user, TyreCatalogueReference $reference): bool
    {
        return $user->can(Permission::CompatibilitiesManage->value);
    }

    public function delete(User $user, TyreCatalogueReference $reference): bool
    {
        return false;
    }
}
