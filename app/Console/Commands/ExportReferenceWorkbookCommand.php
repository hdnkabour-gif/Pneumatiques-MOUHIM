<?php

namespace App\Console\Commands;

use DateTimeInterface;
use Illuminate\Console\Command;
use OpenSpout\Reader\XLSX\Reader;
use ZipArchive;

final class ExportReferenceWorkbookCommand extends Command
{
    protected $signature = 'vehicles:export-reference-workbook
        {path? : Chemin du classeur XLSX}
        {--pretty : Formater le JSON pour lecture}';

    protected $description = 'Exporte toutes les feuilles et toutes les lignes du classeur de référence en JSON, sans modifier la base de données.';

    public function handle(): int
    {
        $path = $this->argument('path') ?: base_path('docs/data/PNEU_MOUHIM_Manager_Catalogue_Operations_V1_3.xlsx');

        if (! is_file($path)) {
            $this->components->error("Classeur introuvable : {$path}");

            return self::FAILURE;
        }

        $zip = new ZipArchive;
        if ($zip->open($path) !== true || $zip->locateName('xl/workbook.xml') === false) {
            $this->components->error('Le fichier fourni ne semble pas être un classeur XLSX valide.');

            return self::FAILURE;
        }
        $zip->close();

        $reader = new Reader;
        $reader->open($path);
        $result = [
            'file' => basename($path),
            'sheets' => [],
        ];

        try {
            foreach ($reader->getSheetIterator() as $sheet) {
                $header = null;
                $rows = [];

                foreach ($sheet->getRowIterator() as $line => $row) {
                    $values = array_map(
                        fn ($value) => $value instanceof DateTimeInterface
                            ? $value->format('Y-m-d H:i:s')
                            : (is_string($value) ? trim($value) : $value),
                        $row->toArray(),
                    );

                    if ($header === null) {
                        $nonEmpty = array_values(array_filter($values, fn ($value) => $value !== null && $value !== ''));
                        if (count($nonEmpty) < 2) {
                            continue;
                        }

                        // Reference sheets have a header row with a stable ID column.
                        $first = (string) ($values[0] ?? '');
                        if (! in_array($first, [
                            'Marque', 'Source_ID', 'Generation_ID', 'Engine_ID',
                            'Fitment_ID', 'Oil_Fitment_ID',
                        ], true)) {
                            continue;
                        }

                        $header = $values;
                        continue;
                    }

                    if (count(array_filter($values, fn ($value) => $value !== null && $value !== '')) === 0) {
                        continue;
                    }

                    $record = ['_excel_row' => $line];
                    foreach ($header as $index => $column) {
                        if ($column !== null && $column !== '') {
                            $record[(string) $column] = $values[$index] ?? null;
                        }
                    }
                    $rows[] = $record;
                }

                if ($header !== null) {
                    $result['sheets'][$sheet->getName()] = [
                        'columns' => array_values(array_filter($header, fn ($value) => $value !== null && $value !== '')),
                        'row_count' => count($rows),
                        'rows' => $rows,
                    ];
                } else {
                    $result['sheets'][$sheet->getName()] = [
                        'columns' => [],
                        'row_count' => 0,
                        'rows' => [],
                        'warning' => 'Aucune ligne d’en-tête reconnue dans les 9 premières lignes.',
                    ];
                }
            }
        } finally {
            $reader->close();
        }

        $flags = JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_INVALID_UTF8_SUBSTITUTE;
        if ($this->option('pretty')) {
            $flags |= JSON_PRETTY_PRINT;
        }

        $json = json_encode($result, $flags);
        if ($json === false) {
            $this->components->error('Impossible de convertir les données du classeur en JSON.');

            return self::FAILURE;
        }

        $this->output->write($json);

        return self::SUCCESS;
    }
}
