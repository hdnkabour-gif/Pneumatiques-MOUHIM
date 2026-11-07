<?php

use App\Models\OilFitment;
use App\Models\TyreFitment;
use App\Models\VehicleMake;
use App\Models\VehicleModel;
use Database\Seeders\ChineseMarketVehicleCatalogueSeeder;

it('adds only confirmed Chinese-market makes and models without invented fitments', function () {
    app(ChineseMarketVehicleCatalogueSeeder::class)->run();

    expect(VehicleMake::query()->where('name', 'AVATR')->exists())->toBeTrue()
        ->and(VehicleModel::query()->whereHas('make', fn ($q) => $q->where('name', 'AVATR'))->pluck('name')->all())
        ->toBe(['07', '11', '12'])
        ->and(VehicleModel::query()->whereHas('make', fn ($q) => $q->where('name', 'XPeng'))->whereIn('name', ['G6', 'G9'])->count())
        ->toBe(2)
        ->and(VehicleModel::query()->whereHas('make', fn ($q) => $q->where('name', 'GAC'))->where('name', 'HYPTEC HT')->exists())
        ->toBeTrue()
        ->and(TyreFitment::query()->count())->toBe(0)
        ->and(OilFitment::query()->count())->toBe(0);
});

it('is idempotent and preserves existing model notes', function () {
    $model = VehicleModel::query()
        ->whereHas('make', fn ($q) => $q->where('name', 'AVATR'))
        ->where('name', '07')
        ->firstOrFail();
    $model->update(['notes' => 'Note saisie par le responsable']);

    $count = VehicleModel::query()->count();
    app(ChineseMarketVehicleCatalogueSeeder::class)->run();
    app(ChineseMarketVehicleCatalogueSeeder::class)->run();

    expect(VehicleModel::query()->count())->toBe($count)
        ->and($model->fresh()->notes)->toBe('Note saisie par le responsable');
});
