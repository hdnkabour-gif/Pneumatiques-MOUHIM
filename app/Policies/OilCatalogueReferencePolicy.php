<?php

namespace App\Policies;

use App\Enums\Permission;
use App\Models\OilCatalogueReference;
use App\Models\User;

/** Reference catalogue (information only): read by whoever sees compatibilities, corrected by whoever manages them. */
class OilCatalogueReferencePolicy
{
    public function viewAny(User $user): bool
    {
        return $user->canAny([Permission::CompatibilitiesView->value, Permission::CompatibilitiesManage->value]);
    }

    public function view(User $user, OilCatalogueReference $reference): bool
    {
        return $this->viewAny($user);
    }

    public function create(User $user): bool
    {
        return false;
    }

    public function update(User $user, OilCatalogueReference $reference): bool
    {
        return $user->can(Permission::CompatibilitiesManage->value);
    }

    public function delete(User $user, OilCatalogueReference $reference): bool
    {
        return false;
    }
}
