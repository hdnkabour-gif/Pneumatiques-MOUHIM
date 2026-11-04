<?php

use App\Enums\RoleName;
use App\Models\Activity;
use App\Models\User;
use Filament\Auth\Pages\Login;
use Livewire\Livewire;

it('shows the login page in French by default', function () {
    $this->get('/admin/login')->assertOk()->assertSee('lang="fr"', false);
});

it('lets every role log in', function (RoleName $role) {
    $user = userWithRole($role, 'M01');

    Livewire::test(Login::class)
        ->fillForm(['email' => $user->email, 'password' => 'password'])
        ->call('authenticate')
        ->assertHasNoFormErrors()
        ->assertRedirect();

    $this->assertAuthenticatedAs($user);
    expect($user->fresh()->last_login_at)->not->toBeNull();
})->with(RoleName::cases());

it('rejects a wrong password with a clear message', function () {
    $user = userWithRole(RoleName::Seller, 'M01');

    Livewire::test(Login::class)
        ->fillForm(['email' => $user->email, 'password' => 'wrong-password'])
        ->call('authenticate')
        ->assertHasFormErrors(['email']);

    $this->assertGuest();
});

it('refuses a deactivated account', function () {
    $user = User::factory()->inactive()->role(RoleName::Seller)->forSites(site('M01'))->create();

    Livewire::test(Login::class)
        ->fillForm(['email' => $user->email, 'password' => 'password'])
        ->call('authenticate')
        ->assertHasFormErrors(['email']);

    $this->assertGuest();
});

it('blocks login after 5 attempts per minute', function () {
    $user = userWithRole(RoleName::Seller, 'M01');

    foreach (range(1, 5) as $_) {
        Livewire::test(Login::class)
            ->fillForm(['email' => $user->email, 'password' => 'wrong'])
            ->call('authenticate');
    }

    // Even the correct password is now refused.
    Livewire::test(Login::class)
        ->fillForm(['email' => $user->email, 'password' => 'password'])
        ->call('authenticate')
        ->assertNotified();

    $this->assertGuest();
});

it('records logins and failed logins in the activity log', function () {
    $user = userWithRole(RoleName::Seller, 'M01');

    Livewire::test(Login::class)
        ->fillForm(['email' => $user->email, 'password' => 'wrong'])
        ->call('authenticate');

    Livewire::test(Login::class)
        ->fillForm(['email' => $user->email, 'password' => 'password'])
        ->call('authenticate');

    expect(Activity::query()->where('event', 'login_failed')->count())->toBe(1)
        ->and(Activity::query()->where('event', 'login')->where('causer_id', $user->id)->first())
        ->not->toBeNull()
        ->site_id->toBe(site('M01')->id);
});

it('has no default account after a fresh install', function () {
    expect(User::query()->count())->toBe(0);
});
