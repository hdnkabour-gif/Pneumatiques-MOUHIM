<?php

namespace App\Filament\Resources\Brands;

use App\Enums\Permission;
use App\Filament\Resources\Brands\Pages\ManageBrands;
use App\Models\Brand;
use BackedEnum;
use Filament\Actions\EditAction;
use Filament\Forms\Components\FileUpload;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\IconColumn;
use Filament\Tables\Columns\ImageColumn;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;

class BrandResource extends Resource
{
    public static function shouldRegisterNavigation(): bool
    {
        return parent::shouldRegisterNavigation() && (auth()->user()?->canAny([Permission::ProductsManage->value]) ?? false);
    }

    protected static ?string $model = Brand::class;

    /** French labels keep their own capitalisation ("Pneus d'occasion"). */
    protected static bool $hasTitleCaseModelLabel = false;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedTag;

    protected static ?int $navigationSort = 50;

    public static function getModelLabel(): string
    {
        return __('catalog.brands.label');
    }

    public static function getPluralModelLabel(): string
    {
        return __('catalog.brands.plural');
    }

    public static function getNavigationGroup(): ?string
    {
        return __('app.navigation.catalog');
    }

    public static function form(Schema $schema): Schema
    {
        return $schema->components([
            TextInput::make('name')->label(__('catalog.brands.fields.name'))->required()->maxLength(100)->unique(ignoreRecord: true),
            FileUpload::make('logo_path')->label(__('catalog.brands.fields.logo'))->image()
                ->acceptedFileTypes(['image/jpeg', 'image/png', 'image/webp'])
                ->disk('public')->directory('catalog/brand-logos')->visibility('public')
                ->imageEditor()->maxSize(3072)->helperText(__('catalog.brands.fields.logo_help')),
            Toggle::make('is_active')->label(__('app.active'))->default(true),
        ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->defaultSort('name')
            ->modifyQueryUsing(fn ($query) => $query->withCount('products'))
            ->columns([
                ImageColumn::make('logo_path')->label(__('catalog.brands.fields.logo'))->disk('public')->square()->size(44),
                TextColumn::make('name')->label(__('catalog.brands.fields.name'))->searchable()->weight('bold'),
                TextColumn::make('products_count')->label(__('catalog.categories.fields.products_count'))->badge(),
                IconColumn::make('is_active')->label(__('app.active'))->boolean(),
            ])
            ->recordActions([EditAction::make()]);
    }

    public static function getPages(): array
    {
        return ['index' => ManageBrands::route('/')];
    }
}
