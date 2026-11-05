<?php

namespace App\Filament\Resources\Vehicles\VehicleMakes;

use App\Filament\Resources\Vehicles\VehicleMakes\Pages\ManageVehicleMakes;
use App\Models\VehicleMake;
use BackedEnum;
use Filament\Actions\EditAction;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\IconColumn;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;

class VehicleMakeResource extends Resource
{
    protected static ?string $model = VehicleMake::class;

    /** French labels keep their own capitalisation. */
    protected static bool $hasTitleCaseModelLabel = false;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedTag;

    protected static ?int $navigationSort = 50;

    protected static ?string $slug = 'vehicle-makes';

    public static function getModelLabel(): string
    {
        return __('vehicles.makes.label');
    }

    public static function getPluralModelLabel(): string
    {
        return __('vehicles.makes.plural');
    }

    public static function getNavigationGroup(): ?string
    {
        return __('app.navigation.vehicles');
    }

    public static function form(Schema $schema): Schema
    {
        return $schema->columns(2)->components([
            TextInput::make('name')->label(__('vehicles.fields.name'))->required()->maxLength(100)->unique(ignoreRecord: true),
            Toggle::make('is_active')->label(__('app.active'))->default(true),
        ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->modifyQueryUsing(fn (Builder $query) => $query->withCount('models'))
            ->defaultSort('name')
            ->columns([
                TextColumn::make('name')->label(__('vehicles.fields.name'))->searchable()->sortable()->weight('bold'),
                TextColumn::make('models_count')->label(__('vehicles.models.plural'))->badge(),
                IconColumn::make('is_active')->label(__('app.active'))->boolean(),
            ])
            ->filters([])
            ->recordActions([EditAction::make()]);
    }

    public static function getPages(): array
    {
        return ['index' => ManageVehicleMakes::route('/')];
    }
}
