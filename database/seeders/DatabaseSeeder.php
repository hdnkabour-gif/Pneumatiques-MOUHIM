<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;

/**
 * Production-safe seeding only: reference data, no user account.
 * The owner account is created with `php artisan app:create-owner`.
 * Demo data lives in DemoSeeder and refuses to run in production.
 */
class DatabaseSeeder extends Seeder
{
    public function run(): void
    {
        $this->call([
            RolesAndPermissionsSeeder::class,
            SitesSeeder::class,
        ]);
    }
}
