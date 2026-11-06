<?php

namespace App\Policies;

use App\Enums\Permission;
use App\Models\User;
use App\Models\WorkOrder;

class WorkOrderPolicy
{
    public function viewAny(User $user): bool
    {
        return $user->can(Permission::WorkOrdersManage->value);
    }

    public function view(User $user, WorkOrder $record): bool
    {
        return $this->viewAny($user) && $user->canAccessSite($record->site_id);
    }

    public function update(User $user, WorkOrder $record): bool
    {
        return $this->view($user, $record);
    }

    public function create(User $user): bool
    {
        return false;
    }

    public function delete(User $user, WorkOrder $record): bool
    {
        return false;
    }
}
