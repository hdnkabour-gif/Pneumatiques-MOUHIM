<?php

namespace App\Filament\Resources\OilDrums;

use App\Enums\OilDrumStatus;
use App\Enums\Permission;
use App\Enums\Unit;
use App\Exceptions\BusinessRuleException;
use App\Filament\Resources\OilDrums\Pages\ListOilDrums;
use App\Filament\Resources\OilDrums\Pages\ViewOilDrum;
use App\Filament\Support\Fields;
use App\Models\OilDrum;
use App\Models\Product;
use App\Models\Site;
use App\Services\Oil\OilDrumService;
use App\Services\Sales\SaleCalculator;
use App\Support\Money;
use BackedEnum;
use Filament\Actions\Action;
use Filament\Actions\ViewAction;
use Filament\Forms\Components\DatePicker;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Infolists\Components\RepeatableEntry;
use Filament\Infolists\Components\TextEntry;
use Filament\Notifications\Notification;
use Filament\Resources\Resource;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;

/** Oil drums (D-06 revised): what is left in each drum, its litre cost and its history. */
class OilDrumResource extends Resource
{
    protected static ?string $model = OilDrum::class;

    protected static bool $hasTitleCaseModelLabel = false;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedBeaker;

    protected static ?int $navigationSort = 25;

    protected static ?string $recordTitleAttribute = 'code';

    /** Sellers see drum levels on the sale screen; the menu entry is for stock managers. */
    public static function shouldRegisterNavigation(): bool
    {
        return parent::shouldRegisterNavigation() && (auth()->user()?->canAny([Permission::StockReceive->value, Permission::StockAdjust->value]) ?? false);
    }

    public static function getModelLabel(): string
    {
        return __('oil_drums.label');
    }

    public static function getPluralModelLabel(): string
    {
        return __('oil_drums.plural');
    }

    public static function getNavigationGroup(): ?string
    {
        return __('app.navigation.stock');
    }

    public static function getEloquentQuery(): Builder
    {
        return parent::getEloquentQuery()->visibleTo(auth()->user())->with(['product', 'site']);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->defaultSort('remaining_ml')
            ->modifyQueryUsing(fn (Builder $query) => $query->orderByRaw("status = 'empty'"))
            ->columns([
                TextColumn::make('code')->label(__('oil_drums.fields.code'))->weight('bold')->searchable(),
                TextColumn::make('product.name')->label(__('oil_drums.fields.product'))->searchable()->wrap(),
                TextColumn::make('site.code')->label(__('oil_drums.fields.site'))->badge(),
                TextColumn::make('status')->label(__('oil_drums.fields.status'))->badge()
                    ->color(fn (OilDrumStatus $state) => match ($state) {
                        OilDrumStatus::Open => 'success', OilDrumStatus::Closed => 'info', OilDrumStatus::Empty => 'gray', OilDrumStatus::Blocked => 'danger',
                    }),
                TextColumn::make('remaining_ml')->label(__('oil_drums.fields.remaining'))->sortable()->weight('bold')
                    ->formatStateUsing(fn (OilDrum $record) => $record->remainingLitres().' / '.OilDrum::litres($record->received_ml))
                    ->color(fn (OilDrum $record) => $record->remaining_ml > 0 && $record->remaining_ml * 10 < $record->received_ml ? 'warning' : null)
                    ->description(fn (OilDrum $record) => $record->remaining_ml > 0 && $record->remaining_ml * 10 < $record->received_ml ? __('oil_drums.low') : null),
                TextColumn::make('cost_per_litre_cents')->label(__('oil_drums.fields.cost_per_litre'))
                    ->formatStateUsing(fn ($state) => Money::format((int) $state))->visible(fn () => Fields::canSeeCosts()),
                TextColumn::make('value')->label(__('oil_drums.fields.value'))->visible(fn () => Fields::canSeeCosts())
                    ->state(fn (OilDrum $record) => Money::format(OilDrumService::costOf($record->cost_per_litre_cents, $record->remaining_ml))),
            ])
            ->filters([
                SelectFilter::make('site_id')->label(__('oil_drums.fields.site'))
                    ->options(fn () => Site::query()->visibleTo(auth()->user())->orderBy('code')->pluck('name', 'id')),
                SelectFilter::make('status')->label(__('oil_drums.fields.status'))->options(OilDrumStatus::class),
            ])
            ->headerActions([self::receiveAction()])
            ->recordActions([ViewAction::make(), ...self::drumActions()]);
    }

    public static function infolist(Schema $schema): Schema
    {
        return $schema->components([
            Section::make()->columns(4)->schema([
                TextEntry::make('code')->label(__('oil_drums.fields.code'))->weight('bold'),
                TextEntry::make('product.name')->label(__('oil_drums.fields.product')),
                TextEntry::make('site.name')->label(__('oil_drums.fields.site')),
                TextEntry::make('status')->label(__('oil_drums.fields.status'))->badge(),
                TextEntry::make('remaining')->label(__('oil_drums.fields.remaining'))->state(fn (OilDrum $record) => $record->remainingLitres())->weight('bold'),
                TextEntry::make('used')->label(__('oil_drums.fields.used'))->state(fn (OilDrum $record) => OilDrum::litres($record->received_ml - $record->remaining_ml)),
                TextEntry::make('received')->label(__('oil_drums.fields.received'))->state(fn (OilDrum $record) => OilDrum::litres($record->received_ml).' / '.OilDrum::litres($record->capacity_ml)),
                TextEntry::make('cost_per_litre_cents')->label(__('oil_drums.fields.cost_per_litre'))
                    ->formatStateUsing(fn ($state) => Money::format((int) $state))->visible(fn () => Fields::canSeeCosts()),
                TextEntry::make('supplier')->label(__('oil_drums.fields.supplier'))->placeholder('—'),
                TextEntry::make('invoice_ref')->label(__('oil_drums.fields.invoice_ref'))->placeholder('—'),
                TextEntry::make('received_at')->label(__('oil_drums.fields.received_at'))->date('d/m/Y'),
                TextEntry::make('opened_at')->label(__('oil_drums.fields.opened_at'))->dateTime('d/m/Y')->placeholder('—'),
            ]),
            Section::make(__('oil_drums.fields.movements'))->schema([
                RepeatableEntry::make('movements')->hiddenLabel()->columns(4)->schema([
                    TextEntry::make('created_at')->label(__('oil_drums.fields.date'))->dateTime('d/m/Y H:i'),
                    TextEntry::make('type')->label(__('oil_drums.fields.type'))->badge(),
                    TextEntry::make('quantity_ml')->label(__('oil_drums.fields.litres'))
                        ->formatStateUsing(fn ($state) => ($state > 0 ? '+' : '−').OilDrum::litres(abs((int) $state))),
                    TextEntry::make('reason')->label(__('oil_drums.fields.reason'))->placeholder('—'),
                ]),
            ]),
        ]);
    }

    public static function receiveAction(): Action
    {
        return Action::make('receive')->label(__('oil_drums.actions.receive'))->icon(Heroicon::OutlinedPlus)
            ->visible(fn () => auth()->user()->can(Permission::StockReceive->value))
            ->schema([
                Select::make('product_id')->label(__('oil_drums.fields.product'))->required()->searchable()
                    ->options(fn () => Product::query()->where('unit', Unit::Litre->value)->where('is_active', true)->orderBy('name')->pluck('name', 'id'))
                    ->helperText(__('oil_drums.product_help')),
                Select::make('site_id')->label(__('oil_drums.fields.site'))->required()
                    ->options(fn () => Site::query()->visibleTo(auth()->user())->where('is_active', true)->orderBy('code')->pluck('name', 'id'))
                    ->default(fn () => auth()->user()->default_site_id),
                TextInput::make('capacity')->label(__('oil_drums.fields.capacity'))->required()->default('208')->inputMode('decimal'),
                TextInput::make('received')->label(__('oil_drums.fields.received'))->inputMode('decimal')->helperText(__('oil_drums.received_help')),
                Fields::money('purchase_cost_cents', __('oil_drums.fields.purchase_cost'))->required(),
                Fields::money('extra_cost_cents', __('oil_drums.fields.extra_cost')),
                TextInput::make('supplier')->label(__('oil_drums.fields.supplier')),
                TextInput::make('invoice_ref')->label(__('oil_drums.fields.invoice_ref')),
                DatePicker::make('received_at')->label(__('oil_drums.fields.received_at'))->default(today()),
            ])
            ->action(function (array $data) {
                try {
                    $drum = app(OilDrumService::class)->receive(Product::query()->findOrFail($data['product_id']), Site::query()->findOrFail($data['site_id']), [
                        'capacity_ml' => SaleCalculator::millilitres($data['capacity']) ?? 0,
                        'received_ml' => SaleCalculator::millilitres($data['received'] ?? null),
                        'purchase_cost_cents' => (int) $data['purchase_cost_cents'],
                        'extra_cost_cents' => (int) ($data['extra_cost_cents'] ?? 0),
                        'supplier' => $data['supplier'] ?? null, 'invoice_ref' => $data['invoice_ref'] ?? null,
                        'received_at' => $data['received_at'] ?? null,
                    ], auth()->user());
                } catch (BusinessRuleException $e) {
                    Notification::make()->danger()->title($e->getMessage())->send();

                    return;
                }
                Notification::make()->success()->title($drum->code)->send();
            });
    }

    /** Correction, transfer, block / unblock. */
    public static function drumActions(): array
    {
        $run = function (callable $callback) {
            try {
                $callback();
                Notification::make()->success()->title(__('work_orders.saved'))->send();
            } catch (BusinessRuleException $e) {
                Notification::make()->danger()->title($e->getMessage())->send();
            }
        };

        return [
            Action::make('adjust')->label(__('oil_drums.actions.adjust'))->icon(Heroicon::OutlinedAdjustmentsHorizontal)
                ->visible(fn () => auth()->user()->canAny([Permission::ApprovalsDecide->value, Permission::InventoryApprove->value]))
                ->schema([
                    TextInput::make('litres')->label(__('oil_drums.adjust_litres'))->required()->helperText(__('oil_drums.adjust_help')),
                    TextInput::make('reason')->label(__('oil_drums.fields.reason'))->required()->maxLength(255),
                ])
                ->action(fn (OilDrum $record, array $data) => $run(function () use ($record, $data) {
                    $negative = str_starts_with(trim($data['litres']), '-');
                    $ml = SaleCalculator::millilitres(ltrim(trim($data['litres']), '-+')) ?? throw new BusinessRuleException(__('oil_drums.errors.quantity'));
                    app(OilDrumService::class)->adjust($record, $negative ? -$ml : $ml, $data['reason'], auth()->user());
                })),
            Action::make('transfer')->label(__('oil_drums.actions.transfer'))->icon(Heroicon::OutlinedTruck)
                ->visible(fn (OilDrum $record) => auth()->user()->can(Permission::TransfersSend->value) && $record->remaining_ml > 0)
                ->schema([
                    Select::make('to_site_id')->label(__('oil_drums.fields.to_site'))->required()
                        ->options(fn (OilDrum $record) => Site::query()->where('is_active', true)->whereKeyNot($record->site_id)->orderBy('code')->pluck('name', 'id')),
                ])
                ->action(fn (OilDrum $record, array $data) => $run(fn () => app(OilDrumService::class)->transfer($record, Site::query()->findOrFail($data['to_site_id']), auth()->user()))),
            Action::make('block')->label(fn (OilDrum $record) => __($record->status === OilDrumStatus::Blocked ? 'oil_drums.actions.unblock' : 'oil_drums.actions.block'))
                ->icon(Heroicon::OutlinedNoSymbol)->color('gray')->requiresConfirmation()
                ->visible(fn () => auth()->user()->canAny([Permission::StockAdjust->value, Permission::ApprovalsDecide->value]))
                ->action(fn (OilDrum $record) => $run(fn () => app(OilDrumService::class)->setBlocked($record, $record->status !== OilDrumStatus::Blocked, auth()->user()))),
        ];
    }

    public static function getPages(): array
    {
        return [
            'index' => ListOilDrums::route('/'),
            'view' => ViewOilDrum::route('/{record}'),
        ];
    }
}
