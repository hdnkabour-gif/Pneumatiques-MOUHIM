<?php

use App\Enums\RoleName;

/*
| Every admin page is protected server-side: typing the URL directly must
| be refused exactly like the hidden menu entry (§21 Permissions).
*/

dataset('admin pages', [
    'sites list' => ['/admin/sites'],
    'site creation' => ['/admin/sites/create'],
    'users list' => ['/admin/users'],
    'user creation' => ['/admin/users/create'],
    'roles list' => ['/admin/roles'],
    'company settings' => ['/admin/company-settings'],
    'activity log' => ['/admin/activities'],
]);

it('gives the owner access to every admin page', function (string $url) {
    $this->actingAs(owner())->get($url)->assertOk();
})->with('admin pages');

it('denies the technician every admin page, even by direct URL', function (string $url) {
    $this->actingAs(userWithRole(RoleName::Technician, 'M01'))->get($url)->assertForbidden();
})->with('admin pages');

it('denies the seller management pages', function (string $url) {
    $this->actingAs(userWithRole(RoleName::Seller, 'M01'))->get($url)->assertForbidden();
})->with([
    '/admin/sites/create',
    '/admin/users',
    '/admin/users/create',
    '/admin/roles',
    '/admin/company-settings',
    '/admin/activities',
]);

it('denies the storekeeper management pages', function (string $url) {
    $this->actingAs(userWithRole(RoleName::Storekeeper, 'DEP'))->get($url)->assertForbidden();
})->with([
    '/admin/users/create',
    '/admin/roles',
    '/admin/company-settings',
    '/admin/activities',
]);

it('lets the site manager see users but not manage them', function () {
    $manager = userWithRole(RoleName::SiteManager, 'M01');

    $this->actingAs($manager)->get('/admin/users')->assertOk();
    $this->actingAs($manager)->get('/admin/users/create')->assertForbidden();
    $this->actingAs($manager)->get('/admin/company-settings')->assertForbidden();
});

it('lets every active role open the dashboard', function (RoleName $role) {
    $this->actingAs(userWithRole($role, 'M01'))->get('/admin')->assertOk();
})->with(RoleName::cases());

it('redirects guests to the login page', function (string $url) {
    $this->get($url)->assertRedirect('/admin/login');
})->with('admin pages');

it('redirects the home page to the application', function () {
    $this->get('/')->assertRedirect('/admin');
});

it('reports the health check', function () {
    $this->get('/up')->assertOk();
});
