<?php

namespace App\Filament\Resources\Categories;

use App\Enums\CategoryType;
use App\Enums\Permission;
use App\Filament\Resources\Categories\Pages\ManageCategories;
use App\Filament\Support\Fields;
use App\Filament\Support\PageHub;
use App\Models\Category;
use App\Support\Decimal;
use BackedEnum;
use Filament\Actions\EditAction;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\IconColumn;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;

class CategoryResource extends Resource
{
    /** Simplicity (§5): setup pages appear in the menu only for those who manage them. */
    public static function shouldRegisterNavigation(): bool
    {
        return PageHub::inMenu(static::class) && parent::shouldRegisterNavigation() && (auth()->user()?->canAny([Permission::ProductsManage->value]) ?? false);
    }

    protected static ?string $model = Category::class;

    /** French labels keep their own capitalisation ("Pneus d'occasion"). */
    protected static bool $hasTitleCaseModelLabel = false;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedFolder;

    protected static ?int $navigationSort = 40;

    public static function getModelLabel(): string
    {
        return __('catalog.categories.label');
    }

    public static function getPluralModelLabel(): string
    {
        return __('catalog.categories.plural');
    }

    public static function getNavigationGroup(): ?string
    {
        return __('app.navigation.catalog');
    }

    public static function form(Schema $schema): Schema
    {
        return $schema->columns(2)->components([
            TextInput::make('name')->label(__('catalog.categories.fields.name'))->required()->maxLength(255),
            TextInput::make('name_ar')->label(__('catalog.categories.fields.name_ar'))->maxLength(255),
            Select::make('parent_id')->label(__('catalog.categories.fields.parent'))
                ->relationship('parent', 'name', fn ($query) => $query->whereNull('parent_id'))
                ->live()
                ->afterStateUpdated(function ($state, $set) {
                    if ($parent = Category::query()->find($state)) {
                        $set('type', $parent->type->value);
                        $set('sku_prefix', $parent->sku_prefix);
                    }
                }),
            Select::make('type')->label(__('catalog.categories.fields.type'))->options(CategoryType::class)->required(),
            TextInput::make('sku_prefix')->label(__('catalog.categories.fields.sku_prefix'))
                ->required()->maxLength(4)->alphaNum()
                ->dehydrateStateUsing(fn ($state) => strtoupper((string) $state)),
            TextInput::make('default_margin_bp')->label(__('catalog.categories.fields.default_margin'))
                ->helperText(__('catalog.categories.fields.default_margin_help'))
                ->suffix('%')->rule('regex:/^\d+([.,]\d{1,2})?$/')
                ->formatStateUsing(fn ($state) => $state === null ? null : Decimal::basisPointsToPercent((int) $state))
                ->dehydrateStateUsing(fn ($state) => blank($state) ? null : Decimal::percentToBasisPoints((string) $state)),
            Toggle::make('is_active')->label(__('app.active'))->default(true),
        ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->modifyQueryUsing(fn ($query) => $query->with('parent')->withCount('products'))
            ->defaultSort('sort_order')
            ->columns([
                TextColumn::make('name')->label(__('catalog.categories.fields.name'))
                    ->formatStateUsing(fn (Category $record) => $record->fullName())->searchable(['name', 'name_ar'])->weight('bold'),
                TextColumn::make('type')->label(__('catalog.categories.fields.type'))->badge(),
                TextColumn::make('sku_prefix')->label(__('catalog.categories.fields.sku_prefix')),
                ...(Fields::canSeeCosts() ? [
                    TextColumn::make('default_margin_bp')->label(__('catalog.categories.fields.default_margin'))
                        ->formatStateUsing(fn ($state) => $state === null ? '—' : Decimal::basisPointsToPercent((int) $state).' %')
                        ->placeholder('—'),
                ] : []),
                TextColumn::make('products_count')->label(__('catalog.categories.fields.products_count'))->badge(),
                IconColumn::make('is_active')->label(__('app.active'))->boolean(),
            ])
            ->filters([SelectFilter::make('type')->label(__('catalog.categories.fields.type'))->options(CategoryType::class)])
            ->recordActions([EditAction::make()]);
    }

    public static function getPages(): array
    {
        return ['index' => ManageCategories::route('/')];
    }
}
