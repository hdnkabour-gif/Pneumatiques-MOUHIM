<?php

namespace Database\Seeders;

use App\Enums\Permission;
use App\Enums\RoleName;
use Illuminate\Database\Seeder;
use Spatie\Permission\Models\Permission as PermissionModel;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;

/**
 * Safe to run in production and on every deploy: creates missing
 * permissions and roles. Default permissions are only granted to a role
 * when it is first created, so the owner's later changes are preserved.
 * The owner role always receives every permission.
 */
class RolesAndPermissionsSeeder extends Seeder
{
    public function run(): void
    {
        app(PermissionRegistrar::class)->forgetCachedPermissions();

        foreach (Permission::cases() as $permission) {
            PermissionModel::findOrCreate($permission->value, 'web');
        }

        foreach (RoleName::cases() as $roleName) {
            $role = Role::query()->where(['name' => $roleName->value, 'guard_name' => 'web'])->first();

            if ($role === null) {
                $role = Role::create(['name' => $roleName->value, 'guard_name' => 'web']);
                $role->syncPermissions(array_map(fn (Permission $p) => $p->value, $roleName->defaultPermissions()));
            }

            if ($roleName === RoleName::Owner) {
                $role->syncPermissions(array_map(fn (Permission $p) => $p->value, Permission::cases()));
            }
        }

        app(PermissionRegistrar::class)->forgetCachedPermissions();
    }
}
