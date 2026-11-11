<?php

namespace App\Filament\Resources\Products;

use App\Enums\ProductKind;
use App\Filament\Actions\StockActions;
use App\Filament\Resources\Products\Pages\CreateProduct;
use App\Filament\Resources\Products\Pages\EditProduct;
use App\Filament\Resources\Products\Pages\ListProducts;
use App\Filament\Resources\StockMovements\StockMovementResource;
use App\Filament\Support\Fields;
use App\Models\Category;
use App\Models\Product;
use App\Models\Site;
use App\Services\Catalog\ProductSearch;
use BackedEnum;
use Filament\Actions\Action;
use Filament\Actions\ActionGroup;
use Filament\Actions\EditAction;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\IconColumn;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Filters\TernaryFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;

/** Stocked goods (tyres, oils, filters, batteries…). Services and bundles have their own screens. */
class ProductResource extends Resource
{
    protected static ?string $model = Product::class;

    /** French labels keep their own capitalisation ("Pneus d'occasion"). */
    protected static bool $hasTitleCaseModelLabel = false;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedCube;

    protected static ?int $navigationSort = 50;

    protected static ?string $recordTitleAttribute = 'name';

    public static function getModelLabel(): string
    {
        return __('catalog.products.label');
    }

    public static function getPluralModelLabel(): string
    {
        return __('catalog.products.plural');
    }

    public static function getNavigationGroup(): ?string
    {
        return __('app.navigation.stock');
    }

    public static function getEloquentQuery(): Builder
    {
        return parent::getEloquentQuery()->where('kind', ProductKind::Goods->value);
    }

    public static function form(Schema $schema): Schema
    {
        return ProductForm::configure($schema);
    }

    public static function table(Table $table): Table
    {
        $sites = Site::query()->visibleTo(auth()->user())->where('is_active', true)->orderBy('code')->get();

        return $table
            ->splitSearchTerms(false)
            ->modifyQueryUsing(fn (Builder $query) => $query->with(['brand', 'category.parent', 'tyreSpec', 'stockLevels']))
            ->defaultSort('name')
            ->searchPlaceholder(__('catalog.search_placeholder'))
            ->columns([
                TextColumn::make('sku')->label(__('catalog.fields.sku'))->sortable()->toggleable()->visibleFrom('md'),
                TextColumn::make('name')->label(__('catalog.fields.name'))->weight('bold')->wrap()
                    ->description(fn (Product $r) => $r->tyreSpec?->sizeLabel())
                    ->searchable(query: fn (Builder $query, string $search) => ProductSearch::apply($query, $search))
                    ->sortable(),
                TextColumn::make('brand.name')->label(__('catalog.fields.brand'))->toggleable()->visibleFrom('md'),
                TextColumn::make('category.name')->label(__('catalog.fields.category'))
                    ->formatStateUsing(fn (Product $r) => $r->category->fullName())->toggleable(isToggledHiddenByDefault: true),
                Fields::moneyColumn('price_retail_cents', __('catalog.fields.price_retail'))->sortable(),
                ...(Fields::canSeeCosts() ? [Fields::moneyColumn('average_cost_cents', __('catalog.fields.average_cost'))->toggleable()] : []),
                ...$sites->map(fn (Site $site) => TextColumn::make('stock_'.$site->code)
                    ->label($site->code)
                    ->state(fn (Product $r) => (int) ($r->stockLevels->firstWhere('site_id', $site->id)?->quantity ?? 0))
                    ->badge()
                    ->color(fn (int $state) => $state > 0 ? 'success' : 'gray')
                    ->alignCenter())->all(),
                IconColumn::make('is_active')->label(__('catalog.fields.is_active'))->boolean()->toggleable(isToggledHiddenByDefault: true),
            ])
            ->filters([
                SelectFilter::make('category_id')->label(__('catalog.fields.category'))
                    ->options(fn () => ProductForm::categoryOptions())
                    ->query(fn (Builder $query, array $data) => $data['value']
                        ? $query->whereIn('category_id', Category::query()->where('id', $data['value'])->orWhere('parent_id', $data['value'])->pluck('id'))
                        : $query),
                SelectFilter::make('brand_id')->label(__('catalog.fields.brand'))->relationship('brand', 'name')->searchable()->preload(),
                TernaryFilter::make('in_stock')->label(__('catalog.filters.in_stock'))
                    ->queries(
                        true: fn (Builder $query) => $query->whereHas('stockLevels', fn ($s) => $s->where('quantity', '>', 0)),
                        false: fn (Builder $query) => $query->whereDoesntHave('stockLevels', fn ($s) => $s->where('quantity', '>', 0)),
                    ),
                TernaryFilter::make('is_active')->label(__('catalog.fields.is_active'))->default(true),
            ])
            ->recordActions([
                EditAction::make(),
                ActionGroup::make([
                    StockActions::receive(),
                    StockActions::adjust(),
                    Action::make('history')->label(__('stock.actions.history'))->icon(Heroicon::OutlinedClock)
                        ->url(fn (Product $r) => StockMovementResource::getUrl('index', ['filters' => ['product_id' => ['value' => $r->id]]])),
                ]),
            ]);
    }

    public static function getPages(): array
    {
        return [
            'index' => ListProducts::route('/'),
            'create' => CreateProduct::route('/create'),
            'edit' => EditProduct::route('/{record}/edit'),
        ];
    }
}
