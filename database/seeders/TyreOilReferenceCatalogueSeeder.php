<?php

namespace Database\\Seeders;

use Illuminate\\Database\\Seeder;
use Illuminate\\Support\\Facades\\Artisan;
use RuntimeException;

class TyreOilReferenceCatalogueSeeder extends Seeder
{
    public function run(): void
    {
        $exit = Artisan::call('catalogue:import-tyre-oil');
        if ($exit !== 0) {
            throw new RuntimeException('Échec de l’import du catalogue de références pneus et huiles.');
        }
    }
}
