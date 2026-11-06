<?php

namespace App\Filament\Resources\Vehicles\VehicleEngines;

use App\Enums\Permission;
use App\Filament\Resources\Vehicles\VehicleEngines\Pages\ManageVehicleEngines;
use App\Filament\Support\VehicleFields;
use App\Models\VehicleEngine;
use BackedEnum;
use Filament\Actions\EditAction;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;

class VehicleEngineResource extends Resource
{
    /** Simplicity (§5): setup pages appear in the menu only for those who manage them. */
    public static function shouldRegisterNavigation(): bool
    {
        return parent::shouldRegisterNavigation() && (auth()->user()?->canAny([Permission::CompatibilitiesManage->value]) ?? false);
    }

    protected static ?string $model = VehicleEngine::class;

    /** French labels keep their own capitalisation. */
    protected static bool $hasTitleCaseModelLabel = false;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedCog;

    protected static ?int $navigationSort = 70;

    protected static ?string $slug = 'vehicle-engines';

    public static function getModelLabel(): string
    {
        return __('vehicles.engines.label');
    }

    public static function getPluralModelLabel(): string
    {
        return __('vehicles.engines.plural');
    }

    public static function getNavigationGroup(): ?string
    {
        return __('app.navigation.vehicles');
    }

    public static function form(Schema $schema): Schema
    {
        return $schema->columns(2)->components([
            VehicleFields::model(),
            VehicleFields::generation(),
            TextInput::make('label')->label(__('vehicles.fields.label'))->required()->maxLength(255),
            TextInput::make('designation')->label(__('vehicles.fields.designation'))->maxLength(255),
            TextInput::make('engine_code')->label(__('vehicles.fields.engine_code'))->maxLength(50),
            TextInput::make('fuel')->label(__('vehicles.fields.fuel'))->maxLength(50),
            TextInput::make('displacement_cc')->label(__('vehicles.fields.displacement'))->integer()->minValue(50)->maxValue(10000),
            TextInput::make('cylinders')->label(__('vehicles.fields.cylinders'))->integer()->minValue(1)->maxValue(16),
            TextInput::make('power_hp')->label(__('vehicles.fields.power_hp'))->integer(),
            TextInput::make('power_kw')->label(__('vehicles.fields.power_kw'))->integer(),
            TextInput::make('torque_nm')->label(__('vehicles.fields.torque'))->integer(),
            TextInput::make('gearbox')->label(__('vehicles.fields.gearbox'))->maxLength(50),
            TextInput::make('gears')->label(__('vehicles.fields.gears'))->integer(),
            TextInput::make('external_id')->label(__('vehicles.fields.external_id'))->maxLength(60)->unique(ignoreRecord: true),
            Textarea::make('notes')->label(__('vehicles.fields.notes'))->columnSpanFull(),
        ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->modifyQueryUsing(fn (Builder $query) => $query->with('model.make'))
            ->columns([
                TextColumn::make('model.name')->label(__('vehicles.fields.model'))->formatStateUsing(fn ($record) => $record->model->fullName())->searchable(),
                TextColumn::make('label')->label(__('vehicles.fields.label'))->searchable()->weight('bold'),
                TextColumn::make('fuel')->label(__('vehicles.fields.fuel'))->placeholder('—'),
                TextColumn::make('displacement_cc')->label(__('vehicles.fields.displacement'))->placeholder('—'),
                TextColumn::make('power_hp')->label(__('vehicles.fields.power_hp'))->placeholder('—'),
                TextColumn::make('gearbox')->label(__('vehicles.fields.gearbox'))->placeholder('—'),
                TextColumn::make('engine_code')->label(__('vehicles.fields.engine_code'))->placeholder('—')->toggleable(),
            ])
            ->filters([])
            ->recordActions([EditAction::make()]);
    }

    public static function getPages(): array
    {
        return ['index' => ManageVehicleEngines::route('/')];
    }
}
