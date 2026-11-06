<?php

namespace App\Actions\Vehicles;

use App\Enums\CompatibilityStatus;
use App\Enums\Permission;
use App\Exceptions\BusinessRuleException;
use App\Models\CompatibilitySource;
use App\Models\OilFitment;
use App\Models\TyreFitment;
use App\Models\User;
use App\Models\VehicleEngine;
use App\Models\VehicleGeneration;
use App\Models\VehicleMake;
use App\Models\VehicleModel;
use App\Support\TyreSize;
use Carbon\CarbonImmutable;
use DateTimeInterface;
use Illuminate\Support\Facades\DB;
use OpenSpout\Reader\XLSX\Reader;

/**
 * Imports the reference workbook (CAR_BRANDS, CAR_MODELS, TECH_SOURCES,
 * CAR_GENERATIONS, CAR_ENGINES, VEHICLE_TIRE_FITMENTS, VEHICLE_OIL_FITMENTS).
 * Idempotent: rows are matched on their workbook IDs (or make + model).
 * Statuses are translated (CompatibilityStatus::fromWorkbook): nothing
 * becomes "verified", and a fitment already verified in the app is never
 * overwritten (D-24, D-48).
 */
class ImportReferenceWorkbookAction
{
    /** @var array<string, array{created: int, updated: int, skipped: int}> */
    private array $counts = [];

    /** @var list<string> */
    private array $errors = [];

    /** @return array{counts: array<string, array{created: int, updated: int, skipped: int}>, errors: list<string>} */
    public function execute(string $path, User $user): array
    {
        if (! $user->can(Permission::CompatibilitiesManage->value)) {
            throw BusinessRuleException::make('app.errors.forbidden');
        }

        $sheets = $this->read($path);

        foreach (['CAR_BRANDS', 'CAR_MODELS', 'TECH_SOURCES', 'CAR_GENERATIONS', 'CAR_ENGINES', 'VEHICLE_TIRE_FITMENTS', 'VEHICLE_OIL_FITMENTS'] as $name) {
            if (! isset($sheets[$name])) {
                throw BusinessRuleException::make('vehicles.import.missing_sheet', ['sheet' => $name]);
            }
        }

        DB::transaction(function () use ($sheets, $user) {
            $this->makes($sheets['CAR_BRANDS']);
            $this->models($sheets['CAR_MODELS']);
            $this->sources($sheets['TECH_SOURCES']);
            $this->generations($sheets['CAR_GENERATIONS']);
            $this->engines($sheets['CAR_ENGINES']);
            $this->tyres($sheets['VEHICLE_TIRE_FITMENTS'], $user);
            $this->oils($sheets['VEHICLE_OIL_FITMENTS'], $user);
        });

        activity('vehicles')->causedBy($user)->event('reference_imported')
            ->withProperties(['file' => basename($path), 'counts' => $this->counts, 'errors' => count($this->errors)])
            ->log('reference_imported');

        return ['counts' => $this->counts, 'errors' => $this->errors];
    }

    /** @return array<string, list<array{line: int, data: array<string, mixed>}>> sheet => rows keyed by header */
    private function read(string $path): array
    {
        $headerKeys = ['Marque', 'Source_ID', 'Generation_ID', 'Engine_ID', 'Fitment_ID', 'Oil_Fitment_ID'];
        $zip = new \ZipArchive;
        if ($zip->open($path) !== true || $zip->locateName('xl/workbook.xml') === false) {
            throw BusinessRuleException::make('vehicles.import.not_a_workbook');
        }
        $zip->close();

        $reader = new Reader;
        $reader->open($path);
        $out = [];

        try {
            foreach ($reader->getSheetIterator() as $sheet) {
                $header = null;
                $rows = [];
                foreach ($sheet->getRowIterator() as $i => $row) {
                    $values = array_map(fn ($v) => is_string($v) ? trim($v) : $v, $row->toArray());
                    if ($header === null) {
                        if (in_array($values[0] ?? null, $headerKeys, true)) {
                            $header = $values;
                        }
                        if ($i > 8) {
                            break;
                        }

                        continue;
                    }
                    if (count(array_filter($values, fn ($v) => $v !== null && $v !== '')) === 0) {
                        continue;
                    }
                    $data = [];
                    foreach ($header as $k => $h) {
                        if ($h !== null && $h !== '') {
                            $data[(string) $h] = ($values[$k] ?? '') === '' ? null : $values[$k];
                        }
                    }
                    $rows[] = ['line' => $i, 'data' => $data];
                }
                if ($header !== null) {
                    $out[$sheet->getName()] = $rows;
                }
            }
        } finally {
            $reader->close();
        }

        return $out;
    }

    private function count(string $sheet, string $what): void
    {
        $this->counts[$sheet] ??= ['created' => 0, 'updated' => 0, 'skipped' => 0];
        $this->counts[$sheet][$what]++;
    }

    private function upsert(string $sheet, string $class, array $match, array $values): object
    {
        $model = $class::query()->firstOrNew($match);
        $model->fill($values);
        $this->count($sheet, $model->exists ? ($model->isDirty() ? 'updated' : 'skipped') : 'created');
        $model->save();

        return $model;
    }

    private function make(string $name): VehicleMake
    {
        $existing = VehicleMake::query()->whereRaw('lower(name) = ?', [mb_strtolower($name)])->first();

        return $existing ?? VehicleMake::query()->create(['name' => $name]);
    }

    private function model(?string $make, ?string $name, string $sheet, int $line): ?VehicleModel
    {
        if (blank($make) || blank($name)) {
            $this->errors[] = __('vehicles.import.missing_vehicle', ['sheet' => $sheet, 'line' => $line]);

            return null;
        }
        $makeModel = $this->make($make);

        return VehicleModel::query()->where('make_id', $makeModel->id)->whereRaw('lower(name) = ?', [mb_strtolower($name)])->first()
            ?? VehicleModel::query()->create(['make_id' => $makeModel->id, 'name' => $name]);
    }

    private function makes(array $rows): void
    {
        foreach ($rows as $r) {
            if (filled($r['data']['Marque'] ?? null)) {
                $existed = VehicleMake::query()->whereRaw('lower(name) = ?', [mb_strtolower($r['data']['Marque'])])->exists();
                $this->make($r['data']['Marque']);
                $this->count('CAR_BRANDS', $existed ? 'skipped' : 'created');
            }
        }
    }

    private function models(array $rows): void
    {
        foreach ($rows as $r) {
            $d = $r['data'];
            $make = $d['Marque'] ?? null;
            $name = $d['Modèle / famille'] ?? null;
            if (blank($make) || blank($name)) {
                $this->errors[] = __('vehicles.import.missing_vehicle', ['sheet' => 'CAR_MODELS', 'line' => $r['line']]);

                continue;
            }
            $makeModel = $this->make($make);
            $existing = VehicleModel::query()->where('make_id', $makeModel->id)->whereRaw('lower(name) = ?', [mb_strtolower($name)])->first();
            $this->upsert('CAR_MODELS', VehicleModel::class, $existing ? ['id' => $existing->id] : ['make_id' => $makeModel->id, 'name' => $name], [
                'body_type' => $d['Carrosserie'] ?? null,
                'notes' => $d['Remarque'] ?? null,
            ]);
        }
    }

    private function sources(array $rows): void
    {
        foreach ($rows as $r) {
            $d = $r['data'];
            if (blank($d['Source_ID'] ?? null)) {
                continue;
            }
            $this->upsert('TECH_SOURCES', CompatibilitySource::class, ['external_id' => $d['Source_ID']], [
                'publisher' => $d['Éditeur'] ?? '—',
                'title' => $d['Titre'] ?? $d['Source_ID'],
                'source_type' => $d['Type de source'] ?? null,
                'url' => $d['URL'] ?? null,
                'source_date' => $this->text($d['Date source / vérification'] ?? null),
                'scope' => $d['Périmètre'] ?? null,
                'reliability' => $d['Fiabilité'] ?? null,
                'notes' => $d['Ce que la source confirme / limite'] ?? null,
            ]);
        }
    }

    private function generations(array $rows): void
    {
        foreach ($rows as $r) {
            $d = $r['data'];
            if (blank($d['Generation_ID'] ?? null) || ! ($model = $this->model($d['Marque'] ?? null, $d['Modèle'] ?? null, 'CAR_GENERATIONS', $r['line']))) {
                continue;
            }
            $this->upsert('CAR_GENERATIONS', VehicleGeneration::class, ['external_id' => $d['Generation_ID']], [
                'model_id' => $model->id,
                'label' => $d['Libellé génération / gamme'] ?? $d['Generation_ID'],
                'code' => $d['Code génération'] ?? null,
                'year_from' => $this->int($d['Année début production'] ?? null),
                'year_to' => $this->int($d['Année fin production'] ?? null),
                'market' => $d['Marché'] ?? null,
                'notes' => trim(($d['Notes techniques'] ?? '').(filled($d['Source_ID'] ?? null) ? ' [Source : '.$d['Source_ID'].']' : '')) ?: null,
            ]);
        }
    }

    private function engines(array $rows): void
    {
        foreach ($rows as $r) {
            $d = $r['data'];
            if (blank($d['Engine_ID'] ?? null) || ! ($model = $this->model($d['Marque'] ?? null, $d['Modèle'] ?? null, 'CAR_ENGINES', $r['line']))) {
                continue;
            }
            $this->upsert('CAR_ENGINES', VehicleEngine::class, ['external_id' => $d['Engine_ID']], [
                'model_id' => $model->id,
                'generation_id' => $this->generationId($d['Generation_ID'] ?? null),
                'label' => $d['Version commerciale'] ?? $d['Engine_ID'],
                'designation' => $d['Désignation moteur'] ?? null,
                'engine_code' => $d['Code moteur'] ?? null,
                'displacement_cc' => $this->int($d['Cylindrée (cm³)'] ?? null),
                'cylinders' => $this->int($d['Cylindres'] ?? null),
                'fuel' => $d['Carburant'] ?? null,
                'power_hp' => $this->int($d['Puissance (ch)'] ?? null),
                'power_kw' => $this->int($d['Puissance (kW)'] ?? null),
                'torque_nm' => $this->int($d['Couple (Nm)'] ?? null),
                'gearbox' => $d['Boîte'] ?? null,
                'gears' => $this->int($d['Rapports'] ?? null),
                'year_from' => $this->int($d['Année début'] ?? null),
                'year_to' => $this->int($d['Année fin'] ?? null),
                'notes' => $d['Notes'] ?? null,
            ]);
        }
    }

    private function tyres(array $rows, User $user): void
    {
        foreach ($rows as $r) {
            $d = $r['data'];
            if (blank($d['Fitment_ID'] ?? null) || ! ($model = $this->model($d['Marque'] ?? null, $d['Modèle'] ?? null, 'VEHICLE_TIRE_FITMENTS', $r['line']))) {
                continue;
            }
            $raw = strtoupper(trim((string) ($d['Dimension'] ?? '')));
            $reinforced = (bool) preg_match('/\d\s*C$/', $raw);
            $size = TyreSize::parse(preg_replace('/(\d)\s*C$/', '$1', $raw));
            if (! $size) {
                $this->errors[] = __('vehicles.import.bad_size', ['line' => $r['line'], 'size' => $d['Dimension'] ?? '']);

                continue;
            }
            if ($this->isVerifiedInApp(TyreFitment::class, $d['Fitment_ID'], 'VEHICLE_TIRE_FITMENTS')) {
                continue;
            }
            $fitment = $this->upsert('VEHICLE_TIRE_FITMENTS', TyreFitment::class, ['external_id' => $d['Fitment_ID']], [
                'model_id' => $model->id,
                'generation_id' => $this->generationId($d['Generation_ID'] ?? null),
                'engine_id' => $this->engineId($d['Engine_ID'] ?? null),
                'source_id' => $this->sourceId($d['Source_ID'] ?? null),
                'status' => CompatibilityStatus::fromWorkbook($d['Statut vérification'] ?? null),
                'source_checked_on' => $this->date($d['Date vérification'] ?? null),
                'trim' => $d['Finition'] ?? null,
                'position' => $this->position($d['Position'] ?? null),
                'width' => $size['width'],
                'aspect_ratio' => $size['aspect_ratio'],
                'rim_diameter' => $size['rim_diameter'],
                'load_index' => $this->text($d['Indice charge'] ?? null),
                'speed_index' => $this->text($d['Indice vitesse'] ?? null),
                'is_xl' => $this->yes($d['XL'] ?? null),
                'is_run_flat' => $this->yes($d['RunFlat'] ?? null),
                'is_reinforced' => $reinforced,
                'notes' => $d['Notes'] ?? null,
            ]);
            if ($fitment->wasRecentlyCreated) {
                $fitment->forceFill(['created_by' => $user->id])->saveQuietly();
            }
        }
    }

    private function oils(array $rows, User $user): void
    {
        foreach ($rows as $r) {
            $d = $r['data'];
            if (blank($d['Oil_Fitment_ID'] ?? null) || ! ($model = $this->model($d['Marque'] ?? null, $d['Modèle'] ?? null, 'VEHICLE_OIL_FITMENTS', $r['line']))) {
                continue;
            }
            if ($this->isVerifiedInApp(OilFitment::class, $d['Oil_Fitment_ID'], 'VEHICLE_OIL_FITMENTS')) {
                continue;
            }
            $capacity = $d['Capacité (L)'] ?? null;
            $fitment = $this->upsert('VEHICLE_OIL_FITMENTS', OilFitment::class, ['external_id' => $d['Oil_Fitment_ID']], [
                'model_id' => $model->id,
                'generation_id' => $this->generationId($d['Generation_ID'] ?? null),
                'engine_id' => $this->engineId($d['Engine_ID'] ?? null),
                'source_id' => $this->sourceId($d['Source_ID requis'] ?? null),
                'status' => CompatibilityStatus::fromWorkbook($d['Statut'] ?? null),
                'engine_label' => $d['Version moteur'] ?? null,
                'viscosity' => $this->text($d['Viscosité SAE'] ?? null),
                'acea' => $this->text($d['ACEA'] ?? null),
                'api' => $this->text($d['API'] ?? null),
                'oem_approval' => $this->text($d['Homologation OEM'] ?? null),
                'capacity_ml' => $capacity === null ? null : (int) round(((float) str_replace(',', '.', (string) $capacity)) * 1000),
                'oil_filter_ref' => $this->text($d['Filtre huile réf.'] ?? null),
                'notes' => $d['Notes'] ?? null,
            ]);
            if ($fitment->wasRecentlyCreated) {
                $fitment->forceFill(['created_by' => $user->id])->saveQuietly();
            }
        }
    }

    private function isVerifiedInApp(string $class, string $externalId, string $sheet): bool
    {
        $existing = $class::query()->where('external_id', $externalId)->first();
        if ($existing && in_array($existing->status, [CompatibilityStatus::Verified, CompatibilityStatus::Obsolete], true)) {
            $this->count($sheet, 'skipped');

            return true;
        }

        return false;
    }

    private function generationId(?string $id): ?int
    {
        return $id ? VehicleGeneration::query()->where('external_id', $id)->value('id') : null;
    }

    private function engineId(?string $id): ?int
    {
        return $id ? VehicleEngine::query()->where('external_id', $id)->value('id') : null;
    }

    private function sourceId(?string $id): ?int
    {
        return $id ? CompatibilitySource::query()->where('external_id', $id)->value('id') : null;
    }

    private function int(mixed $v): ?int
    {
        return is_numeric($v) ? (int) $v : null;
    }

    private function text(mixed $v): ?string
    {
        if ($v instanceof DateTimeInterface) {
            return $v->format('Y-m-d');
        }

        return $v === null ? null : (string) (is_float($v) && floor($v) === $v ? (int) $v : $v);
    }

    private function date(mixed $v): ?string
    {
        if ($v instanceof DateTimeInterface) {
            return $v->format('Y-m-d');
        }
        try {
            return $v ? CarbonImmutable::parse((string) $v)->toDateString() : null;
        } catch (\Throwable) {
            return null;
        }
    }

    private function yes(mixed $v): bool
    {
        return in_array(mb_strtolower(trim((string) $v)), ['oui', 'yes', 'xl', 'x', '1', 'true', 'rf', 'runflat'], true);
    }

    private function position(?string $v): string
    {
        $v = mb_strtolower((string) $v);

        return match (true) {
            str_contains($v, 'avant') && str_contains($v, 'arri') => 'both',
            str_contains($v, 'arri') => 'rear',
            str_contains($v, 'avant') => 'front',
            default => 'both',
        };
    }
}
