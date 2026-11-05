<?php

namespace App\Filament\Support;

use App\Enums\Permission;
use App\Models\CompatibilitySource;
use App\Models\VehicleEngine;
use App\Models\VehicleGeneration;
use App\Models\VehicleModel;
use Filament\Forms\Components\Select;
use Filament\Schemas\Components\Utilities\Get;

final class VehicleFields
{
    /** @return array<int, string> */
    public static function modelOptions(): array
    {
        return VehicleModel::query()->join('vehicle_makes', 'vehicle_makes.id', '=', 'vehicle_models.make_id')
            ->orderBy('vehicle_makes.name')->orderBy('vehicle_models.name')
            ->selectRaw("vehicle_models.id, vehicle_makes.name || ' ' || vehicle_models.name as label")
            ->pluck('label', 'vehicle_models.id')->all();
    }

    public static function model(): Select
    {
        return Select::make('model_id')->label(__('vehicles.fields.model'))->options(fn () => self::modelOptions())
            ->searchable()->required()->live();
    }

    public static function generation(): Select
    {
        return Select::make('generation_id')->label(__('vehicles.fields.generation'))
            ->options(fn (Get $get) => VehicleGeneration::query()->where('model_id', $get('model_id'))->pluck('label', 'id'));
    }

    public static function engine(): Select
    {
        return Select::make('engine_id')->label(__('vehicles.fields.engine'))
            ->options(fn (Get $get) => VehicleEngine::query()->where('model_id', $get('model_id'))->pluck('label', 'id'));
    }

    public static function source(): Select
    {
        return Select::make('source_id')->label(__('vehicles.fields.source'))
            ->options(fn () => CompatibilitySource::query()->orderBy('external_id')->get()->mapWithKeys(fn ($s) => [$s->id => $s->label()]))
            ->searchable()->required();
    }

    /** Users who may see compatibilities that are not verified (§7.3). */
    public static function canSeeUnverified(): bool
    {
        return auth()->user()?->canAny([Permission::CompatibilitiesManage->value, Permission::CompatibilitiesVerify->value]) ?? false;
    }
}
