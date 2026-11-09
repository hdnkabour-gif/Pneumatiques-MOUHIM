<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Reference tyre / oil catalogue (read-only data, no product nor stock).
 * Runs on every deploy (docker/entrypoint.sh uses `set -e`), so a missing or
 * unreadable workbook is reported but never prevents the application from
 * starting: the previous import stays in place.
 */
class TyreOilReferenceCatalogueSeeder extends Seeder
{
    public function run(): void
    {
        $path = base_path('docs/data/PNEU_MOUHIM_Manager_Catalogue_Operations_V1_3.xlsx');

        if (! is_file($path)) {
            $this->command?->warn('Reference workbook not found: tyre/oil catalogue import skipped.');
            Log::warning('Reference workbook not found: tyre/oil catalogue import skipped.', ['path' => $path]);

            return;
        }

        try {
            $exit = Artisan::call('catalogue:import-tyre-oil', ['path' => $path]);
        } catch (Throwable $e) {
            $exit = 1;
            Log::error('Tyre/oil catalogue import failed.', ['error' => $e->getMessage()]);
        }

        if ($exit !== 0) {
            $this->command?->warn('Tyre/oil catalogue import failed: see the logs. Start-up continues.');
        }
    }
}
