<?php

use App\Enums\RoleName;
use App\Models\Site;
use App\Models\User;
use Database\Seeders\DatabaseSeeder;
use Filament\Auth\MultiFactor\App\AppAuthentication;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

pest()->extend(TestCase::class)
    ->use(RefreshDatabase::class)
    ->beforeEach(fn () => $this->seed(DatabaseSeeder::class))
    ->in('Feature');

pest()->extend(TestCase::class)->in('Unit');

function site(string $code): Site
{
    return Site::query()->where('code', $code)->firstOrFail();
}

/** Create an active user with the given role, assigned to the given site codes. */
function userWithRole(RoleName $role, string ...$siteCodes): User
{
    $sites = array_map(fn (string $code) => site($code), $siteCodes ?: ['M01']);

    return User::factory()->role($role)->forSites(...$sites)->create();
}

/** An owner with two-factor authentication already set up (D-47). */
function owner(): User
{
    $owner = userWithRole(RoleName::Owner, 'DEP', 'M01', 'M02');
    $owner->forceFill(['app_authentication_secret' => app(AppAuthentication::class)->generateSecret()])->save();

    return $owner;
}
