<?php

use App\Enums\RoleName;
use App\Models\User;
use Database\Seeders\DemoSeeder;

it('creates the owner account from the command line', function () {
    $this->artisan('app:create-owner', [
        '--name' => 'Mouhim',
        '--email' => 'owner@pneumouhim.ma',
        '--password' => 'Tres-Solide-2026',
    ])->assertSuccessful();

    $owner = User::query()->where('email', 'owner@pneumouhim.ma')->firstOrFail();

    expect($owner->isOwner())->toBeTrue()
        ->and($owner->siteIds())->toHaveCount(3);
});

it('refuses a weak owner password', function () {
    $this->artisan('app:create-owner', [
        '--name' => 'Mouhim', '--email' => 'owner@pneumouhim.ma', '--password' => 'admin',
    ])->assertFailed();

    expect(User::query()->count())->toBe(0);
});

it('refuses to create a second owner without --force', function () {
    owner();

    $this->artisan('app:create-owner', [
        '--name' => 'Autre', '--email' => 'autre@pneumouhim.ma', '--password' => 'Tres-Solide-2026',
    ])->assertFailed();
});

it('loads one demo account per role outside production', function () {
    $this->seed(DemoSeeder::class);

    foreach (RoleName::cases() as $role) {
        expect(User::role($role->value)->where('email', 'like', '%@demo.test')->exists())->toBeTrue();
    }
});

it('refuses to load demo data in production', function () {
    $this->app['env'] = 'production';

    expect(fn () => (new DemoSeeder)->setContainer($this->app)->run())->toThrow(RuntimeException::class);

    $this->app['env'] = 'testing';
    expect(User::query()->count())->toBe(0);
});
