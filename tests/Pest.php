<?php

use App\Enums\RoleName;
use App\Models\Site;
use App\Models\User;
use Database\Seeders\DatabaseSeeder;
use Filament\Auth\MultiFactor\App\AppAuthentication;
use Illuminate\Foundation\Testing\DatabaseMigrations;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

pest()->extend(TestCase::class)
    ->use(RefreshDatabase::class)
    ->beforeEach(fn () => $this->seed(DatabaseSeeder::class))
    ->in('Feature');

pest()->extend(TestCase::class)->in('Unit');

// Concurrency tests need COMMITTED data visible to child processes (two real DB
// connections), so they cannot wrap each test in a transaction. TRUNCATE is refused
// by the append-only activity_log trigger (D-34) — on purpose — so the schema is
// rebuilt instead (migrate:fresh before each test, rollback after).
pest()->extend(TestCase::class)
    ->use(DatabaseMigrations::class)
    ->beforeEach(fn () => $this->seed(DatabaseSeeder::class))
    ->in('Concurrency');

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
