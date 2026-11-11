<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use OpenSpout\Reader\XLSX\Reader;

final class ImportTyreOilReferenceCatalogueCommand extends Command
{
    protected $signature = 'catalogue:import-tyre-oil
        {path? : Chemin du classeur XLSX}
        {--dry-run : Compter les lignes sans écrire en base}';

    protected $description = 'Importe les références pneus et huiles sans créer de produits, prix ou stocks.';

    private const SHEETS = [
        'TIRE_BRANDS' => ['table' => 'tyre_catalogue_references', 'type' => 'brand'],
        'TIRE_MODELS' => ['table' => 'tyre_catalogue_references', 'type' => 'model'],
        'TIRE_SIZES' => ['table' => 'tyre_catalogue_references', 'type' => 'size'],
        'OIL_BRANDS' => ['table' => 'oil_catalogue_references', 'type' => 'brand'],
        'OIL_MODELS' => ['table' => 'oil_catalogue_references', 'type' => 'model'],
    ];

    public function handle(): int
    {
        $path = $this->argument('path') ?: base_path('docs/data/PNEU_MOUHIM_Manager_Catalogue_Operations_V1_3.xlsx');

        if (! is_file($path)) {
            $this->components->error("Classeur introuvable : {$path}");

            return self::FAILURE;
        }

        $reader = new Reader;
        $reader->open($path);
        $counts = [];

        try {
            foreach ($reader->getSheetIterator() as $sheet) {
                $name = $sheet->getName();

                if (! isset(self::SHEETS[$name])) {
                    continue;
                }

                $header = null;
                $count = 0;

                foreach ($sheet->getRowIterator() as $line => $row) {
                    $cells = array_map(fn ($value) => is_string($value) ? trim($value) : $value, $row->toArray());

                    if ($header === null) {
                        $header = $cells;

                        continue;
                    }

                    if (count(array_filter($cells, fn ($value) => $value !== null && $value !== '')) === 0) {
                        continue;
                    }

                    $data = [];

                    foreach ($header as $index => $key) {
                        if ($key !== null && $key !== '') {
                            $value = $cells[$index] ?? null;
                            $data[(string) $key] = $value === '' ? null : $value;
                        }
                    }

                    $config = self::SHEETS[$name];
                    $brand = $data['Marque'] ?? null;
                    $model = $data['Gamme / modèle'] ?? $data['Gamme'] ?? null;
                    $dimension = $data['Dimension affichée'] ?? null;

                    if ($config['type'] === 'brand' && blank($brand)) {
                        continue;
                    }

                    if ($config['type'] === 'model' && (blank($brand) || blank($model))) {
                        continue;
                    }

                    if ($config['type'] === 'size' && blank($dimension)) {
                        continue;
                    }

                    // Include the source row in the identity: oil ranges can repeat the same
                    // commercial family with different viscosities (e.g. Castrol EDGE).
                    // A stable sheet + row key preserves every source row and remains idempotent.
                    $key = hash('sha256', $name.'|'.$line);
                    $status = $data['Statut final'] ?? $data['Statut de compatibilité'] ?? $data['Vérification'] ?? 'to_verify';

                    $payload = [
                        'record_type' => $config['type'],
                        'brand' => $brand,
                        'model' => $model,
                        'dimension' => $dimension,
                        'specifications' => json_encode($data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
                        'verification_status' => $this->normaliseStatus((string) $status),
                        'source_sheet' => $name,
                        'source_row' => $line,
                        'unique_key' => $key,
                        'created_at' => now(),
                        'updated_at' => now(),
                    ];

                    // Insert only: a row already imported may have been corrected in the application (D-59).
                    if (! $this->option('dry-run') && ! DB::table($config['table'])->where('unique_key', $key)->exists()) {
                        DB::table($config['table'])->insert($payload);
                    }

                    $count++;
                }

                $counts[$name] = $count;
            }
        } finally {
            $reader->close();
        }

        foreach ($counts as $sheet => $count) {
            $this->line($sheet.': '.$count);
        }

        $this->components->info(($this->option('dry-run') ? 'Prévisualisation' : 'Import terminé').'. Aucun produit, prix ou mouvement de stock n’a été créé.');

        return self::SUCCESS;
    }

    private function normaliseStatus(string $status): string
    {
        $value = mb_strtolower(trim($status));

        if (str_contains($value, 'confirm') || str_contains($value, 'valid')) {
            return 'to_verify';
        }

        if (str_contains($value, 'vérifi') || str_contains($value, 'verifi')) {
            return 'to_verify';
        }

        return 'to_verify';
    }
}
