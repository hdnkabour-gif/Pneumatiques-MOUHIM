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
            CategoriesSeeder::class,
            ReferenceBrandsSeeder::class,
            ChineseMarketVehicleCatalogueSeeder::class,
            TyreOilReferenceCatalogueSeeder::class,
        ]);

        // Vehicle reference for Morocco (D-59). It takes about a second, so the test suite, which
        // seeds before every test, loads it only in the tests that are about it.
        if (! app()->runningUnitTests()) {
            $this->call(MoroccoVehicleReferenceSeeder::class);
        }
    }
}
