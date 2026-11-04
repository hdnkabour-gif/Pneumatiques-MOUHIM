<?php

namespace Database\Seeders;

use App\Enums\SiteType;
use App\Models\Site;
use Illuminate\Database\Seeder;

/**
 * The three real sites of the company (D-07). Reference data, safe in
 * production; existing rows are never overwritten.
 */
class SitesSeeder extends Seeder
{
    public function run(): void
    {
        $sites = [
            ['code' => 'DEP', 'name' => 'Dépôt Central', 'type' => SiteType::Depot, 'has_workshop' => false, 'sales_enabled' => false],
            ['code' => 'M01', 'name' => 'Magasin 01', 'type' => SiteType::Store, 'has_workshop' => true, 'sales_enabled' => true],
            ['code' => 'M02', 'name' => 'Magasin 02', 'type' => SiteType::Store, 'has_workshop' => true, 'sales_enabled' => true],
        ];

        foreach ($sites as $site) {
            Site::query()->firstOrCreate(['code' => $site['code']], $site);
        }
    }
}
