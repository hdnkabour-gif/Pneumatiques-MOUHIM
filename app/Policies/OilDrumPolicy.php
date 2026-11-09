<?php

namespace App\Policies;

use App\Enums\Permission;
use App\Models\OilDrum;
use App\Models\User;

class OilDrumPolicy
{
    public function viewAny(User $user): bool
    {
        return $user->can(Permission::StockView->value);
    }

    public function view(User $user, OilDrum $record): bool
    {
        return $this->viewAny($user) && $user->canAccessSite($record->site_id);
    }

    /** Drums are received through OilDrumService (stock.receive), never edited directly. */
    public function create(User $user): bool
    {
        return false;
    }

    public function update(User $user, OilDrum $record): bool
    {
        return false;
    }

    public function delete(User $user, OilDrum $record): bool
    {
        return false;
    }
}
