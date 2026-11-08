<?php

namespace App\Filament\Resources\OilCatalogueReferences;

use App\Enums\Permission;
use App\Filament\Resources\OilCatalogueReferences\Pages\ListOilCatalogueReferences;
use App\Models\OilCatalogueReference;
use BackedEnum;
use Filament\Resources\Resource;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;

class OilCatalogueReferenceResource extends Resource
{
    protected static ?string $model = OilCatalogueReference::class;
    protected static bool $hasTitleCaseModelLabel = false;
    protected static string|BackedEnum|null $navigationIcon = \Filament\Support\Icons\Heroicon::OutlinedBeaker;
    protected static ?int $navigationSort = 21;
    protected static ?string $slug = 'oil-reference-catalogue';

    public static function canViewAny(): bool
    {
        return auth()->user()?->canAny([Permission::CompatibilitiesView->value, Permission::CompatibilitiesManage->value]) ?? false;
    }

    public static function getModelLabel(): string { return __('reference_catalogue.oils'); }
    public static function getPluralModelLabel(): string { return __('reference_catalogue.oils'); }
    public static function getNavigationLabel(): string { return __('reference_catalogue.oils'); }
    public static function getNavigationGroup(): ?string { return __('app.navigation.catalog'); }

    public static function getEloquentQuery(): Builder
    {
        return parent::getEloquentQuery()->orderBy('record_type')->orderBy('brand')->orderBy('model');
    }

    public static function table(Table $table): Table
    {
        return $table->columns([
            TextColumn::make('record_type')->label(__('reference_catalogue.fields.type'))->badge(),
            TextColumn::make('brand')->label(__('reference_catalogue.fields.brand'))->searchable()->sortable(),
            TextColumn::make('model')->label(__('reference_catalogue.fields.model'))->searchable()->sortable(),
            TextColumn::make('verification_status')->label(__('reference_catalogue.fields.status'))->badge(),
            TextColumn::make('source_sheet')->label(__('reference_catalogue.fields.source'))->toggleable(),
            TextColumn::make('source_row')->label(__('reference_catalogue.fields.row'))->toggleable(),
            TextColumn::make('specifications')->label(__('reference_catalogue.fields.details'))
                ->formatStateUsing(fn ($state) => collect($state ?? [])->map(fn ($v, $k) => $k.': '.$v)->implode(' · '))
                ->wrap()->limit(220),
        ])->filters([
            SelectFilter::make('record_type')->label(__('reference_catalogue.fields.type'))->options([
                'brand' => __('reference_catalogue.types.brand'),
                'model' => __('reference_catalogue.types.model'),
            ]),
        ])->defaultSort('brand')->striped();
    }

    public static function getPages(): array
    {
        return ['index' => ListOilCatalogueReferences::route('/')];
    }
}
