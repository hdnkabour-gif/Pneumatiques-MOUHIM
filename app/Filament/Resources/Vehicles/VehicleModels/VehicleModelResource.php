<?php

namespace App\Filament\Resources\Vehicles\VehicleModels;

use App\Filament\Resources\Vehicles\VehicleModels\Pages\ManageVehicleModels;
use App\Models\VehicleModel;
use BackedEnum;
use Filament\Actions\EditAction;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\IconColumn;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;

class VehicleModelResource extends Resource
{
    protected static ?string $model = VehicleModel::class;

    /** French labels keep their own capitalisation. */
    protected static bool $hasTitleCaseModelLabel = false;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedTruck;

    protected static ?int $navigationSort = 40;

    protected static ?string $slug = 'vehicle-models';

    public static function getModelLabel(): string
    {
        return __('vehicles.models.label');
    }

    public static function getPluralModelLabel(): string
    {
        return __('vehicles.models.plural');
    }

    public static function getNavigationGroup(): ?string
    {
        return __('app.navigation.vehicles');
    }

    public static function form(Schema $schema): Schema
    {
        return $schema->columns(2)->components([
            Select::make('make_id')->label(__('vehicles.fields.make'))->relationship('make', 'name')->searchable()->preload()->required()
                ->createOptionForm([TextInput::make('name')->label(__('vehicles.fields.name'))->required()->unique('vehicle_makes', 'name')]),
            TextInput::make('name')->label(__('vehicles.fields.name'))->required()->maxLength(100),
            TextInput::make('body_type')->label(__('vehicles.fields.body_type'))->maxLength(100),
            Toggle::make('is_active')->label(__('app.active'))->default(true),
            Textarea::make('notes')->label(__('vehicles.fields.notes'))->columnSpanFull(),
        ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->modifyQueryUsing(fn (Builder $query) => $query->with('make')->withCount(['tyreFitments', 'oilFitments']))
            ->defaultSort('name')
            ->columns([
                TextColumn::make('make.name')->label(__('vehicles.fields.make'))->searchable()->sortable(),
                TextColumn::make('name')->label(__('vehicles.fields.name'))->searchable()->sortable()->weight('bold'),
                TextColumn::make('tyre_fitments_count')->label(__('vehicles.tyre_fitments.plural'))->badge(),
                TextColumn::make('oil_fitments_count')->label(__('vehicles.oil_fitments.plural'))->badge(),
                IconColumn::make('is_active')->label(__('app.active'))->boolean()->toggleable(),
            ])
            ->filters([SelectFilter::make('make_id')->label(__('vehicles.fields.make'))->relationship('make', 'name')->searchable()->preload()])
            ->recordActions([EditAction::make()]);
    }

    public static function getPages(): array
    {
        return ['index' => ManageVehicleModels::route('/')];
    }
}
