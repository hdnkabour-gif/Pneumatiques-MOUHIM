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
 * permissions and roles. Default permissions are granted when a role is
 * first created, and when a permission is first created (new phase) to the
 * roles whose defaults include it. Permissions the owner later removed are
 * therefore never given back. The owner role always receives everything.
 */
class RolesAndPermissionsSeeder extends Seeder
{
    public function run(): void
    {
        app(PermissionRegistrar::class)->forgetCachedPermissions();

        $existing = PermissionModel::query()->where('guard_name', 'web')->pluck('name')->all();
        $new = [];

        foreach (Permission::cases() as $permission) {
            PermissionModel::findOrCreate($permission->value, 'web');
            if (! in_array($permission->value, $existing, true)) {
                $new[] = $permission;
            }
        }

        foreach (RoleName::cases() as $roleName) {
            $role = Role::query()->where(['name' => $roleName->value, 'guard_name' => 'web'])->first();

            if ($role === null) {
                $role = Role::create(['name' => $roleName->value, 'guard_name' => 'web']);
                $role->syncPermissions(array_map(fn (Permission $p) => $p->value, $roleName->defaultPermissions()));
            } elseif ($new !== []) {
                $grant = array_filter($new, fn (Permission $p) => in_array($p, $roleName->defaultPermissions(), true));
                if ($grant !== []) {
                    $role->givePermissionTo(array_map(fn (Permission $p) => $p->value, $grant));
                }
            }

            if ($roleName === RoleName::Owner) {
                $role->syncPermissions(array_map(fn (Permission $p) => $p->value, Permission::cases()));
            }
        }

        app(PermissionRegistrar::class)->forgetCachedPermissions();
    }
}
