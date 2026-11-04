<?php

use App\Enums\RoleName;

it('renders the interface in Arabic, right-to-left, for an Arabic user', function () {
    $user = userWithRole(RoleName::Seller, 'M01');
    $user->update(['locale' => 'ar']);

    $this->actingAs($user)->get('/admin')
        ->assertOk()
        ->assertSee('lang="ar"', false)
        ->assertSee('dir="rtl"', false)
        ->assertSee('مواقع', false);
});

it('renders the interface in French, left-to-right, by default', function () {
    $this->actingAs(userWithRole(RoleName::Seller, 'M01'))->get('/admin')
        ->assertOk()
        ->assertSee('lang="fr"', false)
        ->assertSee('dir="ltr"', false);
});

it('saves the language chosen by a logged-in user', function () {
    $user = userWithRole(RoleName::Technician, 'M01');

    $this->actingAs($user)->get('/locale/ar')->assertRedirect();

    expect($user->fresh()->locale->value)->toBe('ar');
});

it('lets a guest switch the login page to Arabic', function () {
    $this->get('/locale/ar')->assertRedirect();

    $this->get('/admin/login')->assertOk()->assertSee('dir="rtl"', false);
});

it('rejects an unsupported language', function () {
    $this->get('/locale/en')->assertNotFound();
});
