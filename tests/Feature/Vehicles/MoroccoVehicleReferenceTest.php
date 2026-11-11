<?php

use App\Actions\Vehicles\ImportReferenceWorkbookAction;
use App\Enums\CompatibilityStatus;
use App\Models\Activity;
use App\Models\CompatibilitySource;
use App\Models\OilFitment;
use App\Models\TyreCatalogueReference;
use App\Models\TyreFitment;
use App\Models\VehicleEngine;
use App\Models\VehicleGeneration;
use App\Models\VehicleModel;
use App\Support\TyreSize;
use Database\Seeders\MoroccoVehicleReferenceSeeder;
use OpenSpout\Reader\XLSX\Reader;

beforeEach(fn () => $this->seed(MoroccoVehicleReferenceSeeder::class));

/** IDs declared in one workbook sheet (first column of the header row found). */
function workbookIds(string $sheetName, string $idColumn): array
{
    $reader = new Reader;
    $reader->open(base_path(MoroccoVehicleReferenceSeeder::WORKBOOK));
    $ids = [];
    foreach ($reader->getSheetIterator() as $sheet) {
        if ($sheet->getName() !== $sheetName) {
            continue;
        }
        $seen = false;
        foreach ($sheet->getRowIterator() as $row) {
            $first = $row->toArray()[0] ?? null;
            if ($seen && filled($first)) {
                $ids[] = (string) $first;
            }
            $seen = $seen || $first === $idColumn;
        }
    }
    $reader->close();

    return $ids;
}

it('ships a supplement where every row is sourced and nothing is verified', function () {
    $data = MoroccoVehicleReferenceSeeder::supplement();

    $sources = array_column($data['TECH_SOURCES'], 'Source_ID');
    $knownSources = [...$sources, ...workbookIds('TECH_SOURCES', 'Source_ID')];
    $generations = [...array_column($data['CAR_GENERATIONS'], 'Generation_ID'), ...workbookIds('CAR_GENERATIONS', 'Generation_ID')];
    $engines = [...array_column($data['CAR_ENGINES'], 'Engine_ID'), ...workbookIds('CAR_ENGINES', 'Engine_ID')];

    // No duplicate identifier inside the supplement, no clash with the workbook fitments.
    foreach (['TECH_SOURCES' => 'Source_ID', 'CAR_GENERATIONS' => 'Generation_ID', 'CAR_ENGINES' => 'Engine_ID', 'VEHICLE_TIRE_FITMENTS' => 'Fitment_ID', 'VEHICLE_OIL_FITMENTS' => 'Oil_Fitment_ID'] as $sheet => $key) {
        $ids = array_column($data[$sheet], $key);
        expect($ids)->toBe(array_values(array_unique($ids)), "duplicate id in {$sheet}");
    }
    expect(array_intersect(array_column($data['VEHICLE_TIRE_FITMENTS'], 'Fitment_ID'), workbookIds('VEHICLE_TIRE_FITMENTS', 'Fitment_ID')))->toBe([])
        ->and(array_intersect(array_column($data['VEHICLE_OIL_FITMENTS'], 'Oil_Fitment_ID'), workbookIds('VEHICLE_OIL_FITMENTS', 'Oil_Fitment_ID')))->toBe([])
        ->and(array_intersect($sources, workbookIds('TECH_SOURCES', 'Source_ID')))->toBe([]);

    foreach ($data['TECH_SOURCES'] as $source) {
        expect($source['URL'])->toStartWith('https://')
            ->and($source['Éditeur'])->not->toBeEmpty()
            ->and($source['Ce que la source confirme / limite'])->not->toBeEmpty();
    }

    foreach ($data['CAR_GENERATIONS'] as $row) {
        expect($knownSources)->toContain($row['Source_ID']);
        // Production years are not published by these sources: they must stay empty, never guessed.
        expect($row['Année début production'])->toBeNull()->and($row['Année fin production'])->toBeNull();
    }

    foreach ($data['CAR_ENGINES'] as $row) {
        expect($generations)->toContain($row['Generation_ID'])
            ->and($row['Code moteur'])->toBeNull()
            ->and($row['Notes'])->toContain('[Source : ');
    }

    foreach ($data['VEHICLE_TIRE_FITMENTS'] as $row) {
        expect($knownSources)->toContain($row['Source_ID'])
            ->and($generations)->toContain($row['Generation_ID'])
            ->and(TyreSize::parse($row['Dimension']))->not->toBeNull()
            ->and(CompatibilityStatus::fromWorkbook($row['Statut vérification']))->not->toBe(CompatibilityStatus::Verified);
        if ($row['Engine_ID'] !== null) {
            expect($engines)->toContain($row['Engine_ID']);
        }
    }

    foreach ($data['VEHICLE_OIL_FITMENTS'] as $row) {
        expect($knownSources)->toContain($row['Source_ID requis'])
            ->and($engines)->toContain($row['Engine_ID'])
            ->and(CompatibilityStatus::fromWorkbook($row['Statut']))->not->toBe(CompatibilityStatus::Verified);
    }
});

it('loads the workbook and the supplement at deploy time, with nothing verified', function () {
    $data = MoroccoVehicleReferenceSeeder::supplement();

    expect(TyreFitment::query()->count())->toBe(13 + count($data['VEHICLE_TIRE_FITMENTS']))
        ->and(OilFitment::query()->count())->toBe(11 + count($data['VEHICLE_OIL_FITMENTS']))
        ->and(CompatibilitySource::query()->count())->toBe(26 + count($data['TECH_SOURCES']))
        ->and(TyreFitment::query()->where('status', CompatibilityStatus::Verified)->count())->toBe(0)
        ->and(OilFitment::query()->where('status', CompatibilityStatus::Verified)->count())->toBe(0)
        ->and(TyreFitment::query()->whereNull('source_id')->count())->toBe(0)
        ->and(TyreFitment::query()->whereNotNull('created_by')->count())->toBe(0);

    $jogger = TyreFitment::query()->where('external_id', 'TIR-DAC-JOG-REF')->firstOrFail();
    expect($jogger->sizeLabel())->toBe('205/60 R16 92H')
        ->and($jogger->model->fullName())->toBe('Dacia Jogger')
        ->and($jogger->source->external_id)->toBe('DAC-JOG-BROCH-MA25')
        ->and($jogger->status)->toBe(CompatibilityStatus::ToCheck);

    // A value the source itself makes doubtful stays "unverified", with the reason.
    $staria = TyreFitment::query()->where('external_id', 'TIR-HYU-STA-18')->firstOrFail();
    expect($staria->status)->toBe(CompatibilityStatus::Unverified)->and($staria->notes)->toContain('recouper');

    $sonet = OilFitment::query()->where('external_id', 'OIL-KIA-SON-G15')->firstOrFail();
    expect($sonet->viscosity)->toBe('5W-30')->and($sonet->acea)->toBe('A5/B5')->and($sonet->capacity_ml)->toBe(3800)
        ->and($sonet->engine->external_id)->toBe('ENG-KIA-SON-15MPI');

    // The supplement completes the empty fields of an engine that came from the workbook.
    $logan = VehicleEngine::query()->where('external_id', 'ENG-DAC-LOG-DCI95')->firstOrFail();
    expect($logan->displacement_cc)->toBe(1461)->and($logan->torque_nm)->toBe(220)->and($logan->power_hp)->toBe(95);

    // What the source does not publish stays empty.
    $sportage = VehicleEngine::query()->where('external_id', 'ENG-KIA-SPO-16-DCT7')->firstOrFail();
    expect($sportage->fuel)->toBeNull()->and($sportage->engine_code)->toBeNull()
        ->and(VehicleGeneration::query()->whereNotNull('year_from')->count())->toBe(0);

    expect(VehicleModel::query()->whereHas('make', fn ($q) => $q->where('name', 'Dacia'))->where('name', 'Bigster')->exists())->toBeTrue()
        ->and(TyreCatalogueReference::query()->where('source_sheet', MoroccoVehicleReferenceSeeder::SIZES_SHEET)->count())->toBe(count($data['TIRE_SIZES']));
});

it('is idempotent and never overwrites what is already in the application', function () {
    $verified = TyreFitment::query()->where('external_id', 'TIR-DAC-JOG-REF')->firstOrFail();
    $verified->forceFill(['status' => CompatibilityStatus::Verified, 'load_index' => '95'])->saveQuietly();

    $edited = TyreFitment::query()->where('external_id', 'TIR-NIS-MAG-16')->firstOrFail();
    $edited->forceFill(['notes' => 'Note du responsable', 'load_index' => '89'])->saveQuietly();

    $engine = VehicleEngine::query()->where('external_id', 'ENG-DAC-LOG-DCI95')->firstOrFail();
    $engine->forceFill(['torque_nm' => 999, 'displacement_cc' => null])->saveQuietly();

    $model = VehicleModel::query()->where('name', 'Jogger')->firstOrFail();
    $model->update(['notes' => 'Note saisie par le responsable']);

    $counts = [TyreFitment::query()->count(), OilFitment::query()->count(), VehicleEngine::query()->count(), VehicleModel::query()->count(), TyreCatalogueReference::query()->count()];
    $logs = Activity::query()->where('event', 'reference_imported')->count();

    app(MoroccoVehicleReferenceSeeder::class)->run();
    app(MoroccoVehicleReferenceSeeder::class)->run();

    expect([TyreFitment::query()->count(), OilFitment::query()->count(), VehicleEngine::query()->count(), VehicleModel::query()->count(), TyreCatalogueReference::query()->count()])->toBe($counts)
        ->and($verified->fresh()->status)->toBe(CompatibilityStatus::Verified)
        ->and($verified->fresh()->load_index)->toBe('95')
        ->and($edited->fresh()->notes)->toBe('Note du responsable')
        ->and($edited->fresh()->load_index)->toBe('89')
        ->and($engine->fresh()->torque_nm)->toBe(999)       // an existing value is kept
        ->and($engine->fresh()->displacement_cc)->toBe(1461) // an empty one is completed
        ->and($model->fresh()->notes)->toBe('Note saisie par le responsable');

    // Only the run that completed the engine is journalled; a run that changes nothing is silent.
    expect(Activity::query()->where('event', 'reference_imported')->count())->toBe($logs + 1);
});

it('never blocks the deploy when the reference files are unreadable', function () {
    $this->app->bind(ImportReferenceWorkbookAction::class, fn () => throw new RuntimeException('broken'));

    expect(fn () => app(MoroccoVehicleReferenceSeeder::class)->run())->not->toThrow(Throwable::class);
});
