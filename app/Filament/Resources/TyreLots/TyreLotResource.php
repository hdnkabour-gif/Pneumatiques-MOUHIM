<?php

namespace App\Filament\Resources\TyreLots;

use App\Enums\Permission;
use App\Filament\Resources\TyreLots\Pages\ListTyreLots;
use App\Models\Site;
use App\Models\TyreLot;
use App\Services\SettingsService;
use BackedEnum;
use Filament\Resources\Resource;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\Filter;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;

/** New tyres in stock by DOT lot, oldest first, with the age alert (§5.3, D-42). */
class TyreLotResource extends Resource
{
    /** Simplicity (§5): setup pages appear in the menu only for those who manage them. */
    public static function shouldRegisterNavigation(): bool
    {
        return parent::shouldRegisterNavigation() && (auth()->user()?->canAny([Permission::StockReceive->value, Permission::StockAdjust->value]) ?? false);
    }

    protected static ?string $model = TyreLot::class;

    /** French labels keep their own capitalisation ("Pneus d'occasion"). */
    protected static bool $hasTitleCaseModelLabel = false;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedCalendarDays;

    protected static ?int $navigationSort = 30;

    public static function getModelLabel(): string
    {
        return __('stock.old_tyres.label');
    }

    public static function getPluralModelLabel(): string
    {
        return __('stock.old_tyres.plural');
    }

    public static function getNavigationGroup(): ?string
    {
        return __('app.navigation.stock');
    }

    public static function getEloquentQuery(): Builder
    {
        $user = auth()->user();

        return parent::getEloquentQuery()
            ->where('quantity', '>', 0)
            ->with(['product.tyreSpec', 'site'])
            ->when(! $user->canSeeAllSites(), fn (Builder $query) => $query->whereIn('site_id', $user->siteIds()));
    }

    public static function table(Table $table): Table
    {
        $months = (int) app(SettingsService::class)->get('stock.tyre_age_alert_months');
        $limit = now()->subMonths($months);

        return $table
            ->modifyQueryUsing(fn (Builder $query) => $query->orderByRaw('dot_year IS NULL, dot_year, dot_week'))
            ->columns([
                TextColumn::make('product.name')->label(__('stock.fields.product'))->weight('bold')->wrap()
                    ->description(fn (TyreLot $r) => $r->product->tyreSpec?->sizeLabel()),
                TextColumn::make('site.code')->label(__('stock.fields.site'))->badge(),
                TextColumn::make('dot')->label(__('stock.fields.dot'))->state(fn (TyreLot $r) => $r->dotLabel())->placeholder('—'),
                TextColumn::make('age')->label(__('stock.fields.age_months'))->state(fn (TyreLot $r) => $r->ageInMonths())
                    ->placeholder('—')->badge()
                    ->color(fn ($state) => $state !== null && $state > $months ? 'danger' : 'gray'),
                TextColumn::make('quantity')->label(__('stock.fields.quantity'))->alignCenter(),
                TextColumn::make('supplier')->label(__('stock.fields.supplier'))->toggleable(),
            ])
            ->filters([
                Filter::make('old')->label(__('stock.filters.old_tyres', ['months' => $months]))->default()
                    ->query(fn (Builder $query) => $query->whereNotNull('dot_year')->where(fn ($w) => $w
                        ->where('dot_year', '<', $limit->isoWeekYear)
                        ->orWhere(fn ($x) => $x->where('dot_year', $limit->isoWeekYear)->where('dot_week', '<', $limit->isoWeek)))),
                SelectFilter::make('site_id')->label(__('stock.fields.site'))
                    ->options(fn () => Site::query()->visibleTo(auth()->user())->orderBy('code')->pluck('name', 'id')),
            ])
            ->recordUrl(null);
    }

    public static function getPages(): array
    {
        return ['index' => ListTyreLots::route('/')];
    }
}
