<?php

namespace Database\Seeders;

use App\Enums\RoleName;
use App\Models\Site;
use App\Models\User;
use Illuminate\Database\Seeder;
use RuntimeException;

/**
 * Demonstration accounts (one per role). NEVER run in production.
 * Usage: php artisan db:seed --class=DemoSeeder
 * All demo accounts use the password "demo-password" and the domain demo.test.
 */
class DemoSeeder extends Seeder
{
    public function run(): void
    {
        if (app()->isProduction()) {
            throw new RuntimeException(__('app.errors.production_demo'));
        }

        $this->call(DatabaseSeeder::class);

        $dep = Site::query()->where('code', 'DEP')->firstOrFail();
        $m01 = Site::query()->where('code', 'M01')->firstOrFail();
        $m02 = Site::query()->where('code', 'M02')->firstOrFail();

        $accounts = [
            ['owner@demo.test', 'Démo Propriétaire', RoleName::Owner, [$m01, $dep, $m02], 'fr'],
            ['manager.m01@demo.test', 'Démo Responsable M01', RoleName::SiteManager, [$m01], 'fr'],
            ['seller.m01@demo.test', 'Démo Vendeur M01', RoleName::Seller, [$m01], 'fr'],
            ['seller.m02@demo.test', 'Démo Vendeur M02', RoleName::Seller, [$m02], 'ar'],
            ['storekeeper@demo.test', 'Démo Magasinier', RoleName::Storekeeper, [$dep], 'fr'],
            ['tech.m01@demo.test', 'Démo Technicien M01', RoleName::Technician, [$m01], 'ar'],
        ];

        foreach ($accounts as [$email, $name, $role, $sites, $locale]) {
            $user = User::query()->firstOrCreate(['email' => $email], [
                'name' => $name,
                'password' => 'demo-password',
                'locale' => $locale,
                'default_site_id' => $sites[0]->id,
            ]);
            $user->syncRoles([$role->value]);
            $user->sites()->sync(array_map(fn (Site $s) => $s->id, $sites));
        }
    }
}
