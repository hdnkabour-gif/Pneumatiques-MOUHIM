<?php

namespace App\Filament\Resources\Sales;

use App\Enums\SaleStatus;
use App\Filament\Pages\SaleScreen;
use App\Filament\Resources\Sales\Pages\ListSales;
use App\Filament\Resources\Sales\Pages\ViewSale;
use App\Filament\Support\Fields;
use App\Models\Sale;
use App\Models\Site;
use App\Services\Sales\CustomerLookup;
use App\Services\Sales\SaleDocuments;
use App\Support\Money;
use App\Support\Normalize;
use BackedEnum;
use Filament\Actions\Action;
use Filament\Actions\DeleteAction;
use Filament\Actions\ViewAction;
use Filament\Infolists\Components\RepeatableEntry;
use Filament\Infolists\Components\TextEntry;
use Filament\Resources\Resource;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\Filter;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;

/** Invoices and drafts (read-only: selling happens on the sale screen). */
class SaleResource extends Resource
{
    protected static ?string $model = Sale::class;

    protected static bool $hasTitleCaseModelLabel = false;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedDocumentText;

    protected static ?int $navigationSort = 10;

    protected static ?string $recordTitleAttribute = 'number';

    public static function getModelLabel(): string
    {
        return __('sales.label');
    }

    public static function getPluralModelLabel(): string
    {
        return __('sales.plural');
    }

    public static function getNavigationGroup(): ?string
    {
        return __('app.navigation.sales');
    }

    public static function getEloquentQuery(): Builder
    {
        return parent::getEloquentQuery()->visibleTo(auth()->user())->with(['site', 'customer', 'seller']);
    }

    public static function getGloballySearchableAttributes(): array
    {
        return ['number'];
    }

    public static function getGlobalSearchResultTitle(Model $record): string
    {
        return $record->number ?? __('enums.sale_status.draft');
    }

    public static function getGlobalSearchResultDetails(Model $record): array
    {
        return array_filter([
            __('sales.fields.customer') => $record->customer?->name,
            __('sales.fields.total_ttc') => Money::format($record->total_ttc_cents),
        ]);
    }

    public static function infolist(Schema $schema): Schema
    {
        return $schema->components([
            Section::make()->columns(4)->schema([
                TextEntry::make('number')->label(__('sales.fields.number'))->weight('bold')->placeholder(__('enums.sale_status.draft')),
                TextEntry::make('status')->label(__('sales.fields.status'))->badge(),
                TextEntry::make('confirmed_at')->label(__('sales.fields.date'))->dateTime('d/m/Y H:i')->placeholder('—'),
                TextEntry::make('site.name')->label(__('sales.fields.site')),
                TextEntry::make('customer.name')->label(__('sales.fields.customer'))->placeholder(__('sales.walk_in')),
                TextEntry::make('vehicle.plate')->label(__('sales.fields.vehicle'))->placeholder('—')
                    ->formatStateUsing(fn ($state) => Normalize::plateDisplay($state)),
                TextEntry::make('mileage')->label(__('sales.fields.mileage'))->numeric(thousandsSeparator: ' ')->suffix(' km')->placeholder('—'),
                TextEntry::make('seller.name')->label(__('sales.fields.seller')),
                TextEntry::make('po_number')->label(__('sales.fields.po_number'))->placeholder('—'),
            ]),
            Section::make(__('sales.fields.lines'))->schema([
                RepeatableEntry::make('lines')->hiddenLabel()->columns(6)->schema([
                    TextEntry::make('description')->label(__('sales.fields.description'))->columnSpan(2),
                    TextEntry::make('quantity')->label(__('sales.fields.quantity')),
                    TextEntry::make('unit_price_ttc_cents')->label(__('sales.fields.unit_price'))->formatStateUsing(fn ($state) => Money::format((int) $state)),
                    TextEntry::make('discount_bp')->label(__('sales.fields.discount'))->formatStateUsing(fn ($state) => $state ? number_format($state / 100, 1, ',', ' ').' %' : '—'),
                    TextEntry::make('total_ttc_cents')->label(__('sales.fields.total_ttc'))->formatStateUsing(fn ($state) => Money::format((int) $state))->weight('bold'),
                ]),
            ]),
            Section::make(__('sales.fields.totals'))->columns(4)->schema([
                TextEntry::make('total_ht_cents')->label(__('sales.fields.total_ht'))->formatStateUsing(fn ($state) => Money::format((int) $state)),
                TextEntry::make('total_vat_cents')->label(__('sales.fields.total_vat'))->formatStateUsing(fn ($state) => Money::format((int) $state)),
                TextEntry::make('total_ttc_cents')->label(__('sales.fields.total_ttc'))->formatStateUsing(fn ($state) => Money::format((int) $state))->weight('bold'),
                TextEntry::make('credit_cents')->label(__('sales.fields.credit'))->formatStateUsing(fn ($state) => Money::format((int) $state))
                    ->color(fn ($state) => $state > 0 ? 'danger' : 'success'),
                TextEntry::make('margin')->label(__('sales.fields.margin'))->visible(fn () => Fields::canSeeCosts())
                    ->state(fn (Sale $record) => ($m = $record->marginCents()) === null ? __('sales.margin_incomplete') : Money::format($m))
                    ->color(fn (Sale $record) => $record->marginCents() === null ? 'warning' : null),
            ]),
            Section::make(__('sales.fields.payments'))->visible(fn (Sale $record) => ! $record->isDraft())->schema([
                RepeatableEntry::make('payments')->hiddenLabel()->columns(4)->placeholder(__('sales.no_payment'))->schema([
                    TextEntry::make('method')->label(__('sales.fields.method'))->badge(),
                    TextEntry::make('pivot.amount_cents')->label(__('sales.fields.amount'))->formatStateUsing(fn ($state) => Money::format((int) $state)),
                    TextEntry::make('cheque_number')->label(__('sales.fields.cheque_number'))->placeholder('—'),
                    TextEntry::make('bank')->label(__('sales.fields.bank'))->placeholder('—'),
                ]),
            ]),
        ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->defaultSort('id', 'desc')
            ->columns([
                TextColumn::make('number')->label(__('sales.fields.number'))->weight('bold')->searchable()
                    ->placeholder(__('enums.sale_status.draft')),
                TextColumn::make('confirmed_at')->label(__('sales.fields.date'))->dateTime('d/m/Y H:i')->sortable()->placeholder('—'),
                TextColumn::make('site.code')->label(__('sales.fields.site'))->badge(),
                TextColumn::make('customer.name')->label(__('sales.fields.customer'))->placeholder(__('sales.walk_in'))
                    ->searchable(query: fn (Builder $query, string $search) => $query->whereHas('customer', fn (Builder $c) => CustomerLookup::apply($c, $search))),
                Fields::moneyColumn('total_ttc_cents', __('sales.fields.total_ttc')),
                Fields::moneyColumn('credit_cents', __('sales.fields.credit'))->color(fn ($state) => $state > 0 ? 'danger' : null)->toggleable(),
                TextColumn::make('status')->label(__('sales.fields.status'))->badge()
                    ->color(fn (SaleStatus $state) => match ($state) {
                        SaleStatus::Draft => 'gray', SaleStatus::Confirmed => 'success', SaleStatus::Cancelled => 'danger',
                    }),
                TextColumn::make('seller.name')->label(__('sales.fields.seller'))->toggleable(isToggledHiddenByDefault: true),
            ])
            ->filters([
                SelectFilter::make('status')->label(__('sales.fields.status'))->options(SaleStatus::class),
                SelectFilter::make('site_id')->label(__('sales.fields.site'))
                    ->options(fn () => Site::query()->visibleTo(auth()->user())->orderBy('code')->pluck('name', 'id')),
                Filter::make('today')->label(__('sales.filters.today'))->query(fn (Builder $query) => $query->whereDate('confirmed_at', today())),
                Filter::make('credit')->label(__('sales.filters.credit'))->query(fn (Builder $query) => $query->where('credit_cents', '>', 0)),
            ])
            ->recordActions([
                ViewAction::make(),
                ...self::documentActions(),
            ]);
    }

    /** Print, WhatsApp, resume a draft, throw a draft away. */
    public static function documentActions(): array
    {
        return [
            Action::make('pdf')->label(__('sales.actions.print'))->icon(Heroicon::OutlinedPrinter)
                ->visible(fn (Sale $record) => ! $record->isDraft())
                ->url(fn (Sale $record) => route('sales.pdf', $record))->openUrlInNewTab(),
            Action::make('whatsapp')->label('WhatsApp')->icon(Heroicon::OutlinedChatBubbleLeftEllipsis)->color('success')
                ->visible(fn (Sale $record) => ! $record->isDraft() && app(SaleDocuments::class)->whatsappUrl($record) !== null)
                ->url(fn (Sale $record) => app(SaleDocuments::class)->whatsappUrl($record))->openUrlInNewTab(),
            Action::make('resume')->label(__('sales.actions.resume'))->icon(Heroicon::OutlinedPencilSquare)
                ->visible(fn (Sale $record) => $record->isDraft() && SaleScreen::canAccess())
                ->url(fn (Sale $record) => SaleScreen::getUrl(['draft' => $record->id])),
            DeleteAction::make()->label(__('sales.actions.discard_draft'))
                ->visible(fn (Sale $record) => $record->isDraft()),
        ];
    }

    public static function getPages(): array
    {
        return [
            'index' => ListSales::route('/'),
            'view' => ViewSale::route('/{record}'),
        ];
    }
}
