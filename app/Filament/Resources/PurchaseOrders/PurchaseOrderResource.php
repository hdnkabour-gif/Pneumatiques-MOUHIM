<?php

namespace App\Filament\Resources\PurchaseOrders;

use App\Enums\Permission;
use App\Enums\ProductKind;
use App\Enums\Unit;
use App\Filament\Resources\PurchaseOrders\Pages\CreatePurchaseOrder;
use App\Filament\Resources\PurchaseOrders\Pages\ListPurchaseOrders;
use App\Filament\Resources\PurchaseOrders\Pages\ViewPurchaseOrder;
use App\Filament\Support\Fields;
use App\Models\Product;
use App\Models\PurchaseOrder;
use App\Models\Site;
use App\Models\Supplier;
use App\Support\Money;
use BackedEnum;
use Filament\Forms\Components\DatePicker;
use Filament\Forms\Components\Repeater;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Infolists\Components\RepeatableEntry;
use Filament\Infolists\Components\TextEntry;
use Filament\Resources\Resource;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;

/** Supplier orders and receipts (§13): order → receive (total or partial) → stock at its cost. */
class PurchaseOrderResource extends Resource
{
    protected static ?string $model = PurchaseOrder::class;

    protected static bool $hasTitleCaseModelLabel = false;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedClipboardDocumentList;

    protected static ?int $navigationSort = 20;

    protected static ?string $recordTitleAttribute = 'number';

    public static function canViewAny(): bool
    {
        return auth()->user()?->can(Permission::PurchasesManage->value) ?? false;
    }

    public static function canCreate(): bool
    {
        return self::canViewAny();
    }

    public static function canEdit($record): bool
    {
        return false;
    }

    public static function canDelete($record): bool
    {
        return false;
    }

    public static function getModelLabel(): string
    {
        return __('purchases.order');
    }

    public static function getPluralModelLabel(): string
    {
        return __('purchases.orders');
    }

    public static function getNavigationGroup(): ?string
    {
        return __('app.navigation.purchases');
    }

    public static function getEloquentQuery(): Builder
    {
        $user = auth()->user();

        return parent::getEloquentQuery()->with(['supplier', 'site'])
            ->when(! $user->canSeeAllSites(), fn (Builder $query) => $query->whereIn('site_id', $user->siteIds()));
    }

    public static function form(Schema $schema): Schema
    {
        return $schema->components([
            Section::make()->columns(2)->schema([
                Select::make('supplier_id')->label(__('purchases.supplier'))->required()->searchable()
                    ->options(fn () => Supplier::query()->where('is_active', true)->orderBy('name')->pluck('name', 'id')),
                Select::make('site_id')->label(__('purchases.fields.site'))->required()
                    ->options(fn () => Site::query()->visibleTo(auth()->user())->where('is_active', true)->orderBy('code')->pluck('name', 'id'))
                    ->default(fn () => Site::query()->where('code', 'DEP')->value('id')),
                Toggle::make('received_now')->label(__('purchases.fields.received_now'))->live()->default(false),
                TextInput::make('invoice_ref')->label(__('purchases.fields.invoice_ref'))->visible(fn (Get $get) => (bool) $get('received_now')),
                DatePicker::make('expected_at')->label(__('purchases.fields.expected_at'))->visible(fn (Get $get) => ! $get('received_now')),
            ]),
            Section::make(__('purchases.fields.lines'))->schema([
                Repeater::make('lines')->hiddenLabel()->minItems(1)->defaultItems(1)->columns(5)->schema([
                    Select::make('product_id')->label(__('purchases.fields.product'))->required()->searchable()->columnSpan(2)
                        ->options(fn () => Product::query()->where('kind', ProductKind::Goods->value)->where('unit', '!=', Unit::Litre->value)
                            ->where('is_active', true)->orderBy('name')->pluck('name', 'id')),
                    TextInput::make('quantity')->label(__('purchases.fields.quantity'))->integer()->minValue(1)->required(),
                    Fields::money('unit_cost_cents', __('purchases.fields.unit_cost'))->required(),
                    TextInput::make('dot')->label(__('purchases.fields.dot'))->maxLength(4),
                ]),
            ]),
        ]);
    }

    public static function infolist(Schema $schema): Schema
    {
        return $schema->components([
            Section::make()->columns(4)->schema([
                TextEntry::make('number')->label(__('purchases.fields.number'))->weight('bold'),
                TextEntry::make('supplier.name')->label(__('purchases.supplier')),
                TextEntry::make('site.name')->label(__('purchases.fields.site')),
                TextEntry::make('status')->label(__('purchases.fields.status'))->badge()->formatStateUsing(fn ($state) => __('purchases.status.'.$state)),
            ]),
            Section::make(__('purchases.fields.lines'))->schema([
                RepeatableEntry::make('lines')->hiddenLabel()->columns(4)->schema([
                    TextEntry::make('product.name')->label(__('purchases.fields.product')),
                    TextEntry::make('quantity_ordered')->label(__('purchases.fields.quantity')),
                    TextEntry::make('quantity_received')->label(__('purchases.fields.received')),
                    TextEntry::make('unit_cost_cents')->label(__('purchases.fields.unit_cost'))->formatStateUsing(fn ($state) => Money::format((int) $state)),
                ]),
            ]),
        ]);
    }

    public static function table(Table $table): Table
    {
        return $table->defaultSort('id', 'desc')
            ->columns([
                TextColumn::make('number')->label(__('purchases.fields.number'))->weight('bold')->searchable(),
                TextColumn::make('supplier.name')->label(__('purchases.supplier'))->searchable(),
                TextColumn::make('site.code')->label(__('purchases.fields.site'))->badge(),
                TextColumn::make('status')->label(__('purchases.fields.status'))->badge()->formatStateUsing(fn ($state) => __('purchases.status.'.$state))
                    ->color(fn ($state) => match ($state) {
                        'received' => 'success', 'partial' => 'warning', 'cancelled' => 'gray', default => 'info'
                    }),
                TextColumn::make('created_at')->label(__('purchases.fields.date'))->date('d/m/Y'),
            ])
            ->filters([SelectFilter::make('status')->label(__('purchases.fields.status'))->options(__('purchases.status'))]);
    }

    public static function getPages(): array
    {
        return [
            'index' => ListPurchaseOrders::route('/'),
            'create' => CreatePurchaseOrder::route('/create'),
            'view' => ViewPurchaseOrder::route('/{record}'),
        ];
    }
}
