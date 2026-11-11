<?php

namespace App\Filament\Resources\Vehicles\VehicleGenerations;

use App\Enums\Permission;
use App\Filament\Resources\Vehicles\VehicleGenerations\Pages\ManageVehicleGenerations;
use App\Filament\Support\PageHub;
use App\Filament\Support\VehicleFields;
use App\Models\VehicleGeneration;
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

class VehicleGenerationResource extends Resource
{
    /** Simplicity (§5): setup pages appear in the menu only for those who manage them. */
    public static function shouldRegisterNavigation(): bool
    {
        return PageHub::inMenu(static::class) && parent::shouldRegisterNavigation() && (auth()->user()?->canAny([Permission::CompatibilitiesManage->value]) ?? false);
    }

    protected static ?string $model = VehicleGeneration::class;

    /** French labels keep their own capitalisation. */
    protected static bool $hasTitleCaseModelLabel = false;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedRectangleStack;

    protected static ?int $navigationSort = 60;

    protected static ?string $slug = 'vehicle-generations';

    public static function getModelLabel(): string
    {
        return __('vehicles.generations.label');
    }

    public static function getPluralModelLabel(): string
    {
        return __('vehicles.generations.plural');
    }

    public static function getNavigationGroup(): ?string
    {
        return __('app.navigation.vehicles');
    }

    public static function form(Schema $schema): Schema
    {
        return $schema->columns(2)->components([
            VehicleFields::model(),
            TextInput::make('label')->label(__('vehicles.fields.label'))->required()->maxLength(255),
            TextInput::make('code')->label(__('vehicles.fields.code'))->maxLength(50),
            TextInput::make('external_id')->label(__('vehicles.fields.external_id'))->maxLength(60)->unique(ignoreRecord: true),
            TextInput::make('year_from')->label(__('vehicles.fields.year_from'))->integer()->minValue(1950)->maxValue(2100),
            TextInput::make('year_to')->label(__('vehicles.fields.year_to'))->integer()->minValue(1950)->maxValue(2100),
            TextInput::make('market')->label(__('vehicles.fields.market'))->maxLength(50),
            Textarea::make('notes')->label(__('vehicles.fields.notes'))->columnSpanFull(),
        ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->modifyQueryUsing(fn (Builder $query) => $query->with('model.make'))
            ->columns([
                TextColumn::make('model.name')->label(__('vehicles.fields.model'))->formatStateUsing(fn ($record) => $record->model->fullName())->searchable(),
                TextColumn::make('label')->label(__('vehicles.fields.label'))->searchable()->wrap(),
                TextColumn::make('year_from')->label(__('vehicles.fields.year_from'))->placeholder('—'),
                TextColumn::make('year_to')->label(__('vehicles.fields.year_to'))->placeholder('—'),
                TextColumn::make('external_id')->label(__('vehicles.fields.external_id'))->toggleable(isToggledHiddenByDefault: true),
            ])
            ->filters([])
            ->recordActions([EditAction::make()]);
    }

    public static function getPages(): array
    {
        return ['index' => ManageVehicleGenerations::route('/')];
    }
}
