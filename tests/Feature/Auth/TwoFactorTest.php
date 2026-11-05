<?php

use App\Enums\RoleName;
use App\Models\User;
use Filament\Auth\MultiFactor\App\AppAuthentication;
use Filament\Auth\Pages\Login;
use Filament\Facades\Filament;
use Illuminate\Support\Facades\DB;
use Livewire\Livewire;

it('sends an owner without two-factor to his profile to set it up', function () {
    $owner = userWithRole(RoleName::Owner, 'DEP', 'M01', 'M02');

    $this->actingAs($owner)->get('/admin/products')->assertRedirect(Filament::getProfileUrl());
    $this->actingAs($owner)->get(Filament::getProfileUrl())->assertOk()->assertSee("Application d'authentification");
});

it('does not require nor offer two-factor to other roles', function (RoleName $role) {
    $user = userWithRole($role, 'M01');

    $this->actingAs($user)->get('/admin')->assertOk();
    $this->actingAs($user)->get(Filament::getProfileUrl())->assertOk()->assertDontSee("Application d'authentification");
})->with([RoleName::Seller, RoleName::SiteManager, RoleName::Technician]);

it('asks the owner for the code at login, and accepts the right one', function () {
    $owner = owner();
    $provider = app(AppAuthentication::class);

    $login = Livewire::test(Login::class)
        ->fillForm(['email' => $owner->email, 'password' => 'password'])
        ->call('authenticate');

    $this->assertGuest();

    $login->fillForm(['app' => ['code' => '000000']], 'multiFactorChallengeForm')
        ->call('authenticate')
        ->assertHasFormErrors();
    $this->assertGuest();

    $login->fillForm(['app' => ['code' => $provider->getCurrentCode($owner->fresh())]], 'multiFactorChallengeForm')
        ->call('authenticate');

    $this->assertAuthenticatedAs($owner);
});

it('stores the secret encrypted', function () {
    $owner = owner();
    $raw = DB::table('users')->where('id', $owner->id)->value('app_authentication_secret');

    expect($raw)->not->toBe($owner->getAppAuthenticationSecret())
        ->and(User::query()->find($owner->id)->toArray())->not->toHaveKey('app_authentication_secret');
});
