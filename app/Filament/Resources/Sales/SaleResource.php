<?php

namespace App\Filament\Resources\Sales;

use App\Actions\Sales\CreateCreditNoteAction;
use App\Actions\Sales\QuoteActions;
use App\Enums\Permission;
use App\Enums\QuoteStatus;
use App\Enums\RefundMethod;
use App\Enums\SaleKind;
use App\Enums\SaleStatus;
use App\Exceptions\BusinessRuleException;
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
use Filament\Forms\Components\Radio;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Infolists\Components\RepeatableEntry;
use Filament\Infolists\Components\TextEntry;
use Filament\Notifications\Notification;
use Filament\Resources\Resource;
use Filament\Schemas\Components\Grid;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Components\Text;
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
        return __('sales.document');
    }

    public static function getPluralModelLabel(): string
    {
        return __('sales.documents');
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
                TextEntry::make('kind')->label(__('sales.fields.kind'))->badge(),
                TextEntry::make('quote_status')->label(__('sales.fields.quote_status'))->badge()->visible(fn (Sale $record) => $record->isKind(SaleKind::Quote)),
                TextEntry::make('valid_until')->label(__('sales.fields.valid_until'))->date('d/m/Y')->visible(fn (Sale $record) => $record->isKind(SaleKind::Quote)),
                TextEntry::make('originalSale.number')->label(__('sales.fields.original_invoice'))->visible(fn (Sale $record) => $record->isKind(SaleKind::CreditNote))
                    ->url(fn (Sale $record) => $record->original_sale_id ? self::getUrl('view', ['record' => $record->original_sale_id]) : null),
                TextEntry::make('refund_method')->label(__('sales.fields.refund_method'))->badge()->visible(fn (Sale $record) => $record->isKind(SaleKind::CreditNote)),
                TextEntry::make('reason')->label(__('sales.fields.reason'))->visible(fn (Sale $record) => filled($record->reason)),
                TextEntry::make('recapInvoice.number')->label(__('sales.fields.recap_invoice'))->visible(fn (Sale $record) => $record->isKind(SaleKind::DeliveryNote))
                    ->placeholder(__('sales.not_invoiced')),
                TextEntry::make('creditNotes.number')->label(__('sales.fields.credit_notes'))->badge()->visible(fn (Sale $record) => $record->creditNotes()->exists()),
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
            Section::make(__('sales.fields.payments'))->visible(fn (Sale $record) => ! $record->isDraft() && $record->kind->movesStock())->schema([
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
                TextColumn::make('quote_status')->label(__('sales.fields.quote_status'))->badge()->placeholder('—')
                    ->visible(fn ($livewire) => ($livewire->activeTab ?? null) === 'quote'),
                TextColumn::make('valid_until')->label(__('sales.fields.valid_until'))->date('d/m/Y')
                    ->visible(fn ($livewire) => ($livewire->activeTab ?? null) === 'quote'),
                TextColumn::make('recapInvoice.number')->label(__('sales.fields.recap_invoice'))->placeholder(__('sales.not_invoiced'))
                    ->visible(fn ($livewire) => ($livewire->activeTab ?? null) === 'delivery_note'),
                TextColumn::make('site.code')->label(__('sales.fields.site'))->badge(),
                TextColumn::make('customer.name')->label(__('sales.fields.customer'))->placeholder(__('sales.walk_in'))
                    ->searchable(query: fn (Builder $query, string $search) => $query->whereHas('customer', fn (Builder $c) => CustomerLookup::apply($c, $search))),
                Fields::moneyColumn('total_ttc_cents', __('sales.fields.total_ttc')),
                Fields::moneyColumn('credit_cents', __('sales.fields.credit'))->color(fn ($state) => $state > 0 ? 'danger' : null)->toggleable(),
                TextColumn::make('kind')->label(__('sales.fields.kind'))->badge()
                    ->visible(fn ($livewire) => ($livewire->activeTab ?? null) === 'draft'),
                TextColumn::make('seller.name')->label(__('sales.fields.seller'))->toggleable(isToggledHiddenByDefault: true),
            ])
            ->filters([
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

    /** Print, WhatsApp, convert a quote, return goods, resume or discard a draft. */
    public static function documentActions(): array
    {
        return [
            Action::make('convert')->label(__('sales.actions.convert'))->icon(Heroicon::OutlinedShoppingCart)->color('success')
                ->visible(fn (Sale $record) => $record->isKind(SaleKind::Quote) && $record->quote_status === QuoteStatus::Sent && SaleScreen::canAccess())
                ->action(function (Sale $record, $livewire) {
                    try {
                        $draft = app(QuoteActions::class)->toDraft($record, auth()->user());
                    } catch (BusinessRuleException $e) {
                        Notification::make()->danger()->title($e->getMessage())->send();

                        return;
                    }
                    $livewire->redirect(SaleScreen::getUrl(['draft' => $draft->id]));
                }),
            Action::make('refuse')->label(__('sales.actions.refuse'))->icon(Heroicon::OutlinedXMark)->color('gray')
                ->visible(fn (Sale $record) => $record->isKind(SaleKind::Quote) && $record->quote_status === QuoteStatus::Sent && SaleScreen::canAccess())
                ->requiresConfirmation()
                ->action(fn (Sale $record) => app(QuoteActions::class)->refuse($record, auth()->user())),
            self::returnAction(),
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

    /** Return / credit note (D-15): quantities per line, stock back or damaged, how the customer is paid back. */
    public static function returnAction(): Action
    {
        return Action::make('return')->label(__('sales.actions.return'))->icon(Heroicon::OutlinedArrowUturnLeft)->color('warning')
            ->visible(fn (Sale $record) => $record->isKind(SaleKind::Invoice) && $record->status === SaleStatus::Confirmed && SaleScreen::canAccess())
            ->modalHeading(fn (Sale $record) => __('sales.return.title', ['number' => $record->number]))
            ->modalDescription(fn () => auth()->user()->can(Permission::ApprovalsDecide->value) ? null : __('sales.return.needs_owner'))
            ->schema(fn (Sale $record) => [
                ...$record->lines->where('kind', '!=', 'trade_in')->map(fn ($line) => Grid::make(['default' => 2, 'md' => 4])->schema([
                    Text::make($line->description.' ('.$line->quantity.')')->columnSpan(['default' => 2, 'md' => 2]),
                    TextInput::make("lines.{$line->id}.quantity")->label(__('sales.return.quantity'))->integer()->default(0)
                        ->minValue(0)->maxValue(CreateCreditNoteAction::returnable($line))
                        ->disabled(CreateCreditNoteAction::returnable($line) === 0),
                    Toggle::make("lines.{$line->id}.restock")->label(__('sales.return.restock'))->default(true)
                        ->visible($line->kind !== 'service')->inline(false),
                ]))->values()->all(),
                Radio::make('refund')->label(__('sales.return.refund'))->required()
                    ->options(collect(RefundMethod::cases())->filter(fn ($m) => $record->customer_id || $m === RefundMethod::Cash)
                        ->mapWithKeys(fn ($m) => [$m->value => $m->label()])->all())
                    ->default(RefundMethod::Cash->value),
                Textarea::make('reason')->label(__('sales.fields.reason'))->required()->maxLength(255),
            ])
            ->action(function (Sale $record, array $data) {
                try {
                    $result = app(CreateCreditNoteAction::class)->request($record, $data['lines'] ?? [], RefundMethod::from($data['refund']), $data['reason'], auth()->user());
                } catch (BusinessRuleException $e) {
                    Notification::make()->danger()->title($e->getMessage())->send();

                    return;
                }
                $result instanceof Sale
                    ? Notification::make()->success()->title(__('sales.return.done', ['number' => $result->number]))->send()
                    : Notification::make()->success()->title(__('sales.return.sent'))->send();
            });
    }

    public static function getPages(): array
    {
        return [
            'index' => ListSales::route('/'),
            'view' => ViewSale::route('/{record}'),
        ];
    }
}
