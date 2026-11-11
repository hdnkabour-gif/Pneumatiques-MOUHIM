<?php

namespace App\Filament\Resources\TyreCatalogueReferences;

use App\Enums\Permission;
use App\Filament\Resources\TyreCatalogueReferences\Pages\ListTyreCatalogueReferences;
use App\Filament\Support\PageHub;
use App\Models\TyreCatalogueReference;
use BackedEnum;
use Filament\Actions\EditAction;
use Filament\Forms\Components\KeyValue;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;

class TyreCatalogueReferenceResource extends Resource
{
    protected static ?string $model = TyreCatalogueReference::class;

    protected static bool $hasTitleCaseModelLabel = false;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedTruck;

    protected static ?int $navigationSort = 20;

    protected static ?string $slug = 'tyre-reference-catalogue';

    public static function canViewAny(): bool
    {
        return auth()->user()?->canAny([
            Permission::CompatibilitiesView->value,
            Permission::CompatibilitiesManage->value,
        ]) ?? false;
    }

    /** Simplicity (§5): reference data in the menu only for whoever manages the vehicle reference. */
    public static function shouldRegisterNavigation(): bool
    {
        return PageHub::inMenu(static::class) && parent::shouldRegisterNavigation() && (auth()->user()?->can(Permission::CompatibilitiesManage->value) ?? false);
    }

    public static function getModelLabel(): string
    {
        return __('reference_catalogue.tyres');
    }

    public static function getPluralModelLabel(): string
    {
        return __('reference_catalogue.tyres');
    }

    public static function getNavigationLabel(): string
    {
        return __('reference_catalogue.tyres');
    }

    public static function getNavigationGroup(): ?string
    {
        return __('app.navigation.catalog');
    }

    public static function getEloquentQuery(): Builder
    {
        return parent::getEloquentQuery()
            ->orderBy('record_type')
            ->orderBy('brand')
            ->orderBy('model')
            ->orderBy('dimension');
    }

    /** Reference rows can be corrected by whoever manages compatibilities; a correction is never overwritten by a later import. */
    public static function form(Schema $schema): Schema
    {
        return $schema->columns(2)->components([
            TextInput::make('brand')->label(__('reference_catalogue.fields.brand'))->maxLength(255),
            TextInput::make('model')->label(__('reference_catalogue.fields.model'))->maxLength(255),
            TextInput::make('dimension')->label(__('reference_catalogue.fields.dimension'))->maxLength(40),
            Select::make('verification_status')->label(__('reference_catalogue.fields.status'))->options(__('reference_catalogue.statuses'))->required()->selectablePlaceholder(false),
            KeyValue::make('specifications')->label(__('reference_catalogue.fields.details'))->columnSpanFull(),
        ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('record_type')->label(__('reference_catalogue.fields.type'))->badge(),
                TextColumn::make('brand')->label(__('reference_catalogue.fields.brand'))->searchable()->sortable(),
                TextColumn::make('model')->label(__('reference_catalogue.fields.model'))->searchable()->sortable(),
                TextColumn::make('dimension')->label(__('reference_catalogue.fields.dimension'))->searchable()->sortable(),
                TextColumn::make('verification_status')->label(__('reference_catalogue.fields.status'))->badge()
                    ->formatStateUsing(fn (string $state) => __('reference_catalogue.statuses')[$state] ?? $state)
                    ->color(fn (string $state) => $state === 'checked' ? 'success' : 'gray'),
                TextColumn::make('source_sheet')->label(__('reference_catalogue.fields.source'))->toggleable(),
                TextColumn::make('source_row')->label(__('reference_catalogue.fields.row'))->toggleable(),
                TextColumn::make('specifications')
                    ->label(__('reference_catalogue.fields.details'))
                    ->formatStateUsing(fn ($state) => collect($state ?? [])->map(fn ($value, $key) => $key.': '.$value)->implode(' · '))
                    ->wrap()
                    ->limit(180),
            ])
            ->filters([
                SelectFilter::make('record_type')
                    ->label(__('reference_catalogue.fields.type'))
                    ->options([
                        'brand' => __('reference_catalogue.types.brand'),
                        'model' => __('reference_catalogue.types.model'),
                        'size' => __('reference_catalogue.types.size'),
                    ]),
            ])
            ->recordActions([EditAction::make()])
            ->defaultSort('brand')
            ->striped();
    }

    public static function getPages(): array
    {
        return [
            'index' => ListTyreCatalogueReferences::route('/'),
        ];
    }
}
