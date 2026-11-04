<?php

use App\Enums\Permission;
use App\Enums\RoleName;
use App\Filament\Resources\Roles\Pages\EditRole;
use Database\Seeders\RolesAndPermissionsSeeder;
use Livewire\Livewire;
use Spatie\Permission\Models\Role;

it('creates the five default roles with every permission for the owner', function () {
    expect(Role::query()->pluck('name')->sort()->values()->all())
        ->toBe(collect(RoleName::cases())->map->value->sort()->values()->all());

    $owner = Role::findByName(RoleName::Owner->value);
    expect($owner->permissions->pluck('name')->sort()->values()->all())
        ->toBe(collect(Permission::cases())->map->value->sort()->values()->all());
});

it('gives the technician no administration permission', function () {
    expect(Role::findByName(RoleName::Technician->value)->permissions)->toBeEmpty();
});

it('keeps permission changes made by the owner when seeding again', function () {
    $seller = Role::findByName(RoleName::Seller->value);
    $seller->givePermissionTo(Permission::UsersView->value);

    $this->seed(RolesAndPermissionsSeeder::class);

    expect($seller->fresh()->hasPermissionTo(Permission::UsersView->value))->toBeTrue();
});

it('lets the owner change the permissions of a role, effective immediately', function () {
    $seller = userWithRole(RoleName::Seller, 'M01');
    expect($seller->can(Permission::UsersView->value))->toBeFalse();

    $this->actingAs(owner());
    $role = Role::findByName(RoleName::Seller->value);
    $usersView = Spatie\Permission\Models\Permission::findByName(Permission::UsersView->value);
    $sitesView = Spatie\Permission\Models\Permission::findByName(Permission::SitesView->value);

    Livewire::test(EditRole::class, ['record' => $role->getRouteKey()])
        ->fillForm(['permissions' => [$sitesView->id, $usersView->id]])
        ->call('save')
        ->assertHasNoFormErrors();

    expect($seller->fresh()->can(Permission::UsersView->value))->toBeTrue();
});

it('locks the owner role', function () {
    $owner = owner();

    expect($owner->can('update', Role::findByName(RoleName::Owner->value)))->toBeFalse()
        ->and($owner->can('delete', Role::findByName(RoleName::Seller->value)))->toBeFalse();

    $this->actingAs($owner)
        ->get('/admin/roles/'.Role::findByName(RoleName::Owner->value)->id.'/edit')
        ->assertForbidden();
});
