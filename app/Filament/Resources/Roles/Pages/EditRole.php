<?php

namespace App\Filament\Resources\Roles\Pages;

use App\Filament\Resources\Roles\RoleResource;
use Filament\Resources\Pages\EditRecord;
use Spatie\Permission\PermissionRegistrar;

class EditRole extends EditRecord
{
    protected static string $resource = RoleResource::class;

    protected function afterSave(): void
    {
        app(PermissionRegistrar::class)->forgetCachedPermissions();

        activity('roles')
            ->causedBy(auth()->user())
            ->performedOn($this->record)
            ->event('updated')
            ->withProperties(['attributes' => ['permissions' => $this->record->permissions()->pluck('name')->all()]])
            ->log('role_permissions_updated');
    }
}
