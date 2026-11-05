<?php

namespace App\Filament\Resources\StockMovements;

use App\Enums\StockMovementType;
use App\Filament\Resources\StockMovements\Pages\ListStockMovements;
use App\Filament\Support\Fields;
use App\Models\Product;
use App\Models\Site;
use App\Models\StockMovement;
use BackedEnum;
use Filament\Forms\Components\DatePicker;
use Filament\Resources\Resource;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\Filter;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;

/** Read-only stock ledger; filtered on one product it is the product's stock card (§6.2). */
class StockMovementResource extends Resource
{
    protected static ?string $model = StockMovement::class;

    /** French labels keep their own capitalisation ("Pneus d'occasion"). */
    protected static bool $hasTitleCaseModelLabel = false;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedArrowsRightLeft;

    protected static ?int $navigationSort = 20;

    public static function getModelLabel(): string
    {
        return __('stock.movements.label');
    }

    public static function getPluralModelLabel(): string
    {
        return __('stock.movements.plural');
    }

    public static function getNavigationGroup(): ?string
    {
        return __('app.navigation.stock');
    }

    public static function getEloquentQuery(): Builder
    {
        $user = auth()->user();

        return parent::getEloquentQuery()
            ->with(['product', 'usedTyre', 'site', 'user', 'lot'])
            ->when(! $user->canSeeAllSites(), fn (Builder $query) => $query->whereIn('site_id', $user->siteIds()));
    }

    public static function table(Table $table): Table
    {
        return $table
            ->defaultSort('id', 'desc')
            ->columns([
                TextColumn::make('created_at')->label(__('stock.fields.date'))->dateTime('d/m/Y H:i')->sortable(),
                TextColumn::make('product.name')->label(__('stock.fields.product'))->wrap()
                    ->state(fn (StockMovement $r) => $r->product?->name ?? $r->usedTyre?->code),
                TextColumn::make('site.code')->label(__('stock.fields.site'))->badge(),
                TextColumn::make('type')->label(__('stock.fields.type'))->badge(),
                TextColumn::make('quantity')->label(__('stock.fields.quantity'))->alignCenter()->weight('bold')
                    ->formatStateUsing(fn (int $state) => ($state > 0 ? '+' : '').$state)
                    ->color(fn (int $state) => $state > 0 ? 'success' : 'danger'),
                TextColumn::make('lot.dot')->label(__('stock.fields.dot'))->state(fn (StockMovement $r) => $r->lot?->dotLabel())->placeholder('—')->toggleable(),
                ...(Fields::canSeeCosts() ? [Fields::moneyColumn('unit_cost_cents', __('stock.fields.unit_cost'))->toggleable()] : []),
                TextColumn::make('reason')->label(__('stock.fields.reason'))->wrap()->toggleable(),
                TextColumn::make('user.name')->label(__('stock.fields.user'))->toggleable(),
            ])
            ->filters([
                SelectFilter::make('product_id')->label(__('stock.fields.product'))
                    ->options(fn () => Product::query()->orderBy('name')->pluck('name', 'id'))->searchable(),
                SelectFilter::make('site_id')->label(__('stock.fields.site'))
                    ->options(fn () => Site::query()->visibleTo(auth()->user())->orderBy('code')->pluck('name', 'id')),
                SelectFilter::make('type')->label(__('stock.fields.type'))->options(StockMovementType::class)->multiple(),
                Filter::make('date')->schema([
                    DatePicker::make('from')->label(__('stock.fields.date').' ≥'),
                    DatePicker::make('until')->label(__('stock.fields.date').' ≤'),
                ])->query(fn (Builder $query, array $data) => $query
                    ->when($data['from'] ?? null, fn ($query, $d) => $query->whereDate('created_at', '>=', $d))
                    ->when($data['until'] ?? null, fn ($query, $d) => $query->whereDate('created_at', '<=', $d))),
            ]);
    }

    public static function getPages(): array
    {
        return ['index' => ListStockMovements::route('/')];
    }
}
