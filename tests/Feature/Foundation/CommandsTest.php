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

describe('owner bootstrap from environment variables', function () {
    $set = function (array $vars) {
        foreach ($vars as $key => $value) {
            putenv("{$key}={$value}");
            $_ENV[$key] = $_SERVER[$key] = $value;
        }
    };

    afterEach(function () {
        foreach (['OWNER_NAME', 'OWNER_EMAIL', 'OWNER_PASSWORD'] as $key) {
            putenv($key);
            unset($_ENV[$key], $_SERVER[$key]);
        }
    });

    it('does nothing when the variables are absent', function () {
        $this->artisan('app:create-owner', ['--from-env' => true])->assertSuccessful();

        expect(User::query()->count())->toBe(0);
    });

    it('creates the first owner from the variables', function () use ($set) {
        $set(['OWNER_NAME' => 'Mouhim', 'OWNER_EMAIL' => 'owner@pneumouhim.ma', 'OWNER_PASSWORD' => 'Tres-Solide-2026']);

        $this->artisan('app:create-owner', ['--from-env' => true])->assertSuccessful();

        $owner = User::query()->where('email', 'owner@pneumouhim.ma')->firstOrFail();
        expect($owner->isOwner())->toBeTrue()->and($owner->name)->toBe('Mouhim');
    });

    it('never creates a second owner, even if the variables are left behind', function () use ($set) {
        owner();
        $set(['OWNER_EMAIL' => 'second@pneumouhim.ma', 'OWNER_PASSWORD' => 'Tres-Solide-2026']);

        $this->artisan('app:create-owner', ['--from-env' => true])->assertSuccessful();

        expect(User::query()->where('email', 'second@pneumouhim.ma')->exists())->toBeFalse()
            ->and(User::role(RoleName::Owner->value)->count())->toBe(1);
    });

    it('refuses a weak password from the variables', function () use ($set) {
        $set(['OWNER_EMAIL' => 'owner@pneumouhim.ma', 'OWNER_PASSWORD' => 'admin']);

        $this->artisan('app:create-owner', ['--from-env' => true])->assertFailed();

        expect(User::query()->count())->toBe(0);
    });
});
