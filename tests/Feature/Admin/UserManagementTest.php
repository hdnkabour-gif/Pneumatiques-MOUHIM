<?php

use App\Actions\Users\SaveUserAction;
use App\Enums\RoleName;
use App\Filament\Resources\Users\Pages\CreateUser;
use App\Filament\Resources\Users\Pages\EditUser;
use App\Filament\Resources\Users\Pages\ListUsers;
use App\Models\User;
use Illuminate\Validation\ValidationException;
use Livewire\Livewire;

it('lets the owner create a seller assigned to M01', function () {
    $this->actingAs(owner());

    Livewire::test(CreateUser::class)
        ->fillForm([
            'name' => 'Karim Vendeur',
            'email' => 'karim@example.ma',
            'password' => 'Vendeur2026x',
            'role' => RoleName::Seller->value,
            'sites' => [site('M01')->id],
            'default_site_id' => site('M01')->id,
            'locale' => 'ar',
        ])
        ->call('create')
        ->assertHasNoFormErrors();

    $user = User::query()->where('email', 'karim@example.ma')->firstOrFail();

    expect($user->hasRole(RoleName::Seller->value))->toBeTrue()
        ->and($user->siteIds())->toBe([site('M01')->id])
        ->and($user->default_site_id)->toBe(site('M01')->id)
        ->and($user->locale->value)->toBe('ar');
});

it('requires a strong enough password', function () {
    $this->actingAs(owner());

    Livewire::test(CreateUser::class)
        ->fillForm([
            'name' => 'Faible', 'email' => 'faible@example.ma', 'password' => '123',
            'role' => RoleName::Seller->value, 'sites' => [site('M01')->id], 'locale' => 'fr',
        ])
        ->call('create')
        ->assertHasFormErrors(['password']);
});

it('rejects a default site that is not assigned', function () {
    $owner = owner();

    expect(fn () => app(SaveUserAction::class)->execute(null, [
        'name' => 'X', 'email' => 'x@example.ma', 'password' => 'Password2026',
        'role' => RoleName::Seller->value, 'sites' => [site('M01')->id], 'default_site_id' => site('M02')->id,
    ], $owner))->toThrow(ValidationException::class);
});

it('prevents a non-owner from granting the owner role', function () {
    $manager = userWithRole(RoleName::SiteManager, 'M01');
    $manager->givePermissionTo('users.manage');

    expect(fn () => app(SaveUserAction::class)->execute(null, [
        'name' => 'Pirate', 'email' => 'p@example.ma', 'password' => 'Password2026',
        'role' => RoleName::Owner->value, 'sites' => [site('M01')->id],
    ], $manager))->toThrow(ValidationException::class);
});

it('prevents a user from deactivating their own account', function () {
    $owner = owner();
    $this->actingAs($owner);

    Livewire::test(EditUser::class, ['record' => $owner->getRouteKey()])
        ->fillForm(['is_active' => false])
        ->call('save')
        ->assertHasFormErrors(['is_active']);

    expect($owner->fresh()->is_active)->toBeTrue();
});

it('allows demoting an owner while another owner remains', function () {
    $owner = owner();
    $other = owner();

    // Demote the second owner: fine, one remains.
    app(SaveUserAction::class)->execute($other, [
        'name' => $other->name, 'email' => $other->email, 'role' => RoleName::Seller->value,
        'sites' => [site('M01')->id], 'is_active' => true,
    ], $owner);

    expect(User::role(RoleName::Owner->value)->count())->toBe(1);
});

it('refuses to remove the very last active owner', function () {
    $owner = owner();

    expect(fn () => app(SaveUserAction::class)->execute($owner, [
        'name' => $owner->name, 'email' => $owner->email, 'role' => RoleName::Seller->value,
        'sites' => [site('M01')->id], 'is_active' => true,
    ], $owner))->toThrow(ValidationException::class);

    expect($owner->fresh()->isOwner())->toBeTrue();
});

it('shows a site manager only the users of their sites', function () {
    $manager = userWithRole(RoleName::SiteManager, 'M01');
    $sellerM01 = userWithRole(RoleName::Seller, 'M01');
    $sellerM02 = userWithRole(RoleName::Seller, 'M02');

    $this->actingAs($manager);

    Livewire::test(ListUsers::class)
        ->assertCanSeeTableRecords([$manager, $sellerM01])
        ->assertCanNotSeeTableRecords([$sellerM02]);
});

it('never deletes a user account', function () {
    $owner = owner();

    expect($owner->can('delete', userWithRole(RoleName::Seller, 'M01')))->toBeFalse();
});

it('does not let a user manager edit an owner account', function () {
    $manager = userWithRole(RoleName::SiteManager, 'M01');
    $manager->givePermissionTo('users.manage');

    expect($manager->can('update', owner()))->toBeFalse()
        ->and($manager->can('update', userWithRole(RoleName::Seller, 'M01')))->toBeTrue();
});
