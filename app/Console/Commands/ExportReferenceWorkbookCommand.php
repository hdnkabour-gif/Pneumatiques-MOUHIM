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
        {--output= : Fichier JSON de sortie}
        {--pretty : Formater le JSON pour lecture}';

    protected $description = 'Exporte toutes les feuilles et toutes les lignes du classeur de référence en JSON, sans modifier la base de données.';

    public function handle(): int
    {
        $path = $this->argument('path') ?: base_path('docs/data/PNEU_MOUHIM_Manager_Catalogue_Operations_V1_3.xlsx');

        if (!is_file($path)) {
            $this->components->error("Classeur introuvable : {$path}");

            return self::FAILURE;
        }

        $zip = new \\ZipArchive;
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
                $rows = [];

                foreach ($sheet->getRowIterator() as $line => $row) {
                    $values = array_map(
                        fn ($value) => $value instanceof DateTimeInterface
                            ? $value->format('Y-m-d H:i:s')
                            : (is_string($value) ? trim($value) : $value),
                        $row->toArray(),
                    );

                    if (count(array_filter($values, fn ($value) => $value !== null && $value !== '')) === 0) {
                        continue;
                    }

                    $rows[] = [
                        'excel_row' => $line,
                        'cells' => $values,
                    ];
                }

                $result['sheets'][$sheet->getName()] = [
                    'row_count' => count($rows),
                    'rows' => $rows,
                ];
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

        $output = $this->option('output');
        if ($output) {
            $directory = dirname($output);
            if (!is_dir($directory) && !mkdir($directory, 0775, true) && !is_dir($directory)) {
                $this->components->error("Impossible de créer le dossier : {$directory}");

                return self::FAILURE;
            }

            if (file_put_contents($output, $json) === false) {
                $this->components->error("Impossible d'écrire le fichier : {$output}");

                return self::FAILURE;
            }

            $this->components->info("Extraction terminée : {$output}");
            foreach ($result['sheets'] as $name => $sheet) {
                $this->line($name.': '.$sheet['row_count'].' lignes');
            }

            return self::SUCCESS;
        }

        $this->output->write($json);

        return self::SUCCESS;
    }
}
