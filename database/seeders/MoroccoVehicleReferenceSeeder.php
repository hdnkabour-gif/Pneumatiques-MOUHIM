<?php

namespace Database\Seeders;

use App\Actions\Vehicles\ImportReferenceWorkbookAction;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Vehicle reference for the Moroccan market (D-59): the reference workbook,
 * then the sourced supplement `database/data/morocco_reference_supplement.json`
 * (generations, engines, tyre and oil fitments read on official Moroccan
 * manufacturer / importer documents).
 *
 * Runs on every deploy. It only completes: nothing already in the database is
 * overwritten and nothing is ever imported as "verified" (D-24, D-48).
 * A missing or unreadable file is reported but never blocks start-up.
 */
class MoroccoVehicleReferenceSeeder extends Seeder
{
    public const SUPPLEMENT = 'database/data/morocco_reference_supplement.json';

    public const WORKBOOK = 'docs/data/PNEU_MOUHIM_Manager_Catalogue_Operations_V1_3.xlsx';

    public const SIZES_SHEET = 'SUPPLEMENT_MA_2026_11';

    public function run(): void
    {
        try {
            $supplement = self::supplement();
            $sizes = $supplement['TIRE_SIZES'] ?? [];
            unset($supplement['_meta'], $supplement['TIRE_SIZES']);

            $report = app(ImportReferenceWorkbookAction::class)->executeSystem(
                base_path(self::WORKBOOK),
                $supplement,
                basename(self::SUPPLEMENT),
            );

            $this->tyreSizes($sizes);

            foreach ($report['errors'] as $error) {
                Log::warning('Vehicle reference import: '.$error);
            }
        } catch (Throwable $e) {
            Log::error('Vehicle reference import failed.', ['error' => $e->getMessage()]);
            $this->command?->warn('Vehicle reference import failed: see the logs. Start-up continues.');
        }
    }

    /** @return array<string, mixed> */
    public static function supplement(): array
    {
        $path = base_path(self::SUPPLEMENT);

        return is_file($path) ? json_decode((string) file_get_contents($path), true, flags: JSON_THROW_ON_ERROR) : [];
    }

    /**
     * Sizes quoted by the new fitments, added to the reference tyre catalogue
     * (information only: no product, price or stock).
     *
     * @param  list<array<string, mixed>>  $sizes
     */
    private function tyreSizes(array $sizes): void
    {
        foreach (array_values($sizes) as $i => $size) {
            $key = hash('sha256', self::SIZES_SHEET.'|'.$size['Dimension affichée']);

            if (DB::table('tyre_catalogue_references')->where('unique_key', $key)->exists()) {
                continue;
            }

            DB::table('tyre_catalogue_references')->insert([
                'record_type' => 'size',
                'dimension' => $size['Dimension affichée'],
                'specifications' => json_encode($size, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
                'verification_status' => 'to_verify',
                'source_sheet' => self::SIZES_SHEET,
                'source_row' => $i + 1,
                'unique_key' => $key,
                'created_at' => now(),
                'updated_at' => now(),
            ]);
        }
    }
}
