<?php

namespace App\Filament\Resources\StockLevels;

use App\Enums\StockMovementType;
use App\Filament\Resources\Products\ProductForm;
use App\Filament\Resources\StockLevels\Pages\ListStockLevels;
use App\Filament\Support\Fields;
use App\Models\Category;
use App\Models\Site;
use App\Models\StockLevel;
use App\Services\Catalog\ProductSearch;
use App\Services\SettingsService;
use App\Support\Money;
use BackedEnum;
use Filament\Resources\Resource;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\Filter;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\DB;

/** Stock per site (§6.2): quantity, reserved, available, minimum, value (owner only). */
class StockLevelResource extends Resource
{
    protected static ?string $model = StockLevel::class;

    /** French labels keep their own capitalisation ("Pneus d'occasion"). */
    protected static bool $hasTitleCaseModelLabel = false;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedArchiveBox;

    protected static ?int $navigationSort = 10;

    public static function getModelLabel(): string
    {
        return __('stock.levels.label');
    }

    public static function getPluralModelLabel(): string
    {
        return __('stock.levels.plural');
    }

    public static function getNavigationGroup(): ?string
    {
        return __('app.navigation.stock');
    }

    public static function getEloquentQuery(): Builder
    {
        $user = auth()->user();

        return parent::getEloquentQuery()
            ->select('stock_levels.*')
            ->selectSub(
                DB::table('product_site_thresholds')->select('min_quantity')
                    ->whereColumn('product_site_thresholds.product_id', 'stock_levels.product_id')
                    ->whereColumn('product_site_thresholds.site_id', 'stock_levels.site_id'),
                'min_quantity',
            )
            ->with(['product.tyreSpec', 'product.brand', 'site'])
            ->when(! $user->canSeeAllSites(), fn (Builder $query) => $query->whereIn('stock_levels.site_id', $user->siteIds()));
    }

    public static function table(Table $table): Table
    {
        $dormantDays = (int) app(SettingsService::class)->get('stock.dormant_days');

        return $table
            ->splitSearchTerms(false)
            ->defaultSort('product_id')
            ->searchPlaceholder(__('catalog.search_placeholder'))
            ->columns([
                TextColumn::make('product.name')->label(__('stock.fields.product'))->weight('bold')->wrap()
                    ->description(fn (StockLevel $r) => trim($r->product->sku.' '.($r->product->tyreSpec?->sizeLabel() ?? '')))
                    ->searchable(query: fn (Builder $query, string $search) => $query->whereHas('product', fn (Builder $p) => ProductSearch::apply($p, $search))),
                TextColumn::make('site.code')->label(__('stock.fields.site'))->badge(),
                TextColumn::make('quantity')->label(__('stock.fields.quantity'))->alignCenter()->sortable()
                    ->color(fn (StockLevel $r) => $r->quantity <= 0 ? 'danger' : ($r->min_quantity !== null && $r->quantity < $r->min_quantity ? 'warning' : null))
                    ->weight('bold'),
                TextColumn::make('reserved')->label(__('stock.fields.reserved'))->alignCenter()->toggleable()->visibleFrom('md'),
                TextColumn::make('available')->label(__('stock.fields.available'))->alignCenter()
                    ->state(fn (StockLevel $r) => $r->available()),
                TextColumn::make('min_quantity')->label(__('stock.fields.minimum'))->alignCenter()->placeholder('—')->visibleFrom('md'),
                ...(Fields::canSeeCosts() ? [
                    TextColumn::make('value')->label(__('stock.fields.value'))->alignEnd()
                        ->state(fn (StockLevel $r) => $r->product->average_cost_cents === null ? null : $r->quantity * $r->product->average_cost_cents)
                        ->formatStateUsing(fn ($state) => Money::format($state))
                        ->placeholder(__('catalog.fields.margin_missing')),
                ] : []),
            ])
            ->filters([
                SelectFilter::make('site_id')->label(__('stock.fields.site'))
                    ->options(fn () => Site::query()->visibleTo(auth()->user())->orderBy('code')->pluck('name', 'id')),
                SelectFilter::make('category')->label(__('catalog.fields.category'))
                    ->options(fn () => ProductForm::categoryOptions())
                    ->query(fn (Builder $query, array $data) => $data['value']
                        ? $query->whereHas('product', fn ($p) => $p->whereIn('category_id', Category::query()->where('id', $data['value'])->orWhere('parent_id', $data['value'])->pluck('id')))
                        : $query),
                Filter::make('below_minimum')->label(__('stock.filters.below_minimum'))
                    ->query(fn (Builder $query) => $query->whereExists(fn ($s) => $s->from('product_site_thresholds as t')
                        ->whereColumn('t.product_id', 'stock_levels.product_id')->whereColumn('t.site_id', 'stock_levels.site_id')
                        ->whereColumn('stock_levels.quantity', '<', 't.min_quantity'))),
                Filter::make('out_of_stock')->label(__('stock.filters.out_of_stock'))
                    ->query(fn (Builder $query) => $query->where('stock_levels.quantity', '<=', 0)),
                Filter::make('dormant')->label(__('stock.filters.dormant', ['days' => $dormantDays]))
                    ->query(fn (Builder $query) => $query->where('stock_levels.quantity', '>', 0)->whereNotExists(fn ($s) => $s->from('stock_movements as m')
                        ->whereColumn('m.product_id', 'stock_levels.product_id')->whereColumn('m.site_id', 'stock_levels.site_id')
                        ->where('m.quantity', '<', 0)->whereIn('m.type', [StockMovementType::Sale->value, StockMovementType::WorkshopConsumption->value])
                        ->where('m.created_at', '>=', now()->subDays($dormantDays)))),
            ])
            ->recordUrl(null);
    }

    public static function getPages(): array
    {
        return ['index' => ListStockLevels::route('/')];
    }
}
