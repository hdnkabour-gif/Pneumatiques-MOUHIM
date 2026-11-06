<?php

namespace App\Filament\Resources\Customers;

use App\Enums\CustomerType;
use App\Enums\Permission;
use App\Enums\PriceLevel;
use App\Filament\Resources\Customers\Pages\CreateCustomer;
use App\Filament\Resources\Customers\Pages\EditCustomer;
use App\Filament\Resources\Customers\Pages\ListCustomers;
use App\Filament\Resources\Customers\Pages\ViewCustomer;
use App\Filament\Resources\Customers\RelationManagers\SalesRelationManager;
use App\Filament\Resources\Customers\RelationManagers\VehiclesRelationManager;
use App\Filament\Support\Fields;
use App\Models\Customer;
use App\Services\Sales\CustomerLookup;
use App\Support\Money;
use App\Support\Normalize;
use BackedEnum;
use Filament\Actions\EditAction;
use Filament\Actions\ViewAction;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
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
use Illuminate\Database\Eloquent\Model;

/** Customer file shared by the three sites (§9). Credit terms are set by the owner only. */
class CustomerResource extends Resource
{
    protected static ?string $model = Customer::class;

    protected static bool $hasTitleCaseModelLabel = false;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedUsers;

    protected static ?int $navigationSort = 20;

    protected static ?string $recordTitleAttribute = 'name';

    public static function getModelLabel(): string
    {
        return __('customers.label');
    }

    public static function getPluralModelLabel(): string
    {
        return __('customers.plural');
    }

    public static function getNavigationGroup(): ?string
    {
        return __('app.navigation.sales');
    }

    /** Global search: name, phone in any format, plate, ICE (§5). */
    public static function getGloballySearchableAttributes(): array
    {
        return ['name'];
    }

    public static function modifyGlobalSearchQuery(Builder $query, string $search): void
    {
        $query->getQuery()->wheres = [];
        CustomerLookup::apply($query, $search);
    }

    public static function getGlobalSearchResultDetails(Model $record): array
    {
        return array_filter([
            __('customers.fields.phone') => $record->phone,
            __('customers.fields.plates') => $record->vehicles->map(fn ($v) => Normalize::plateDisplay($v->plate))->implode(', ') ?: null,
        ]);
    }

    public static function getGlobalSearchEloquentQuery(): Builder
    {
        return parent::getGlobalSearchEloquentQuery()->with('vehicles');
    }

    public static function canManageCredit(): bool
    {
        return auth()->user()?->can(Permission::ApprovalsDecide->value) ?? false;
    }

    public static function form(Schema $schema): Schema
    {
        return $schema->components([
            Section::make()->columns(2)->schema([
                TextInput::make('name')->label(__('customers.fields.name'))->required()->maxLength(255),
                TextInput::make('phone')->label(__('customers.fields.phone'))->tel()->required()->maxLength(30),
                Select::make('type')->label(__('customers.fields.type'))->options(CustomerType::class)
                    ->default(CustomerType::Individual)->required()->live(),
            ]),
            Section::make(__('customers.sections.company'))->columns(3)
                ->visible(fn (Get $get) => in_array($get('type'), [CustomerType::Company, CustomerType::Company->value], true))
                ->schema([
                    TextInput::make('ice')->label(__('customers.fields.ice'))->maxLength(20)->regex('/^\d{15}$/')
                        ->validationMessages(['regex' => __('customers.errors.ice_format')]),
                    TextInput::make('tax_id')->label(__('customers.fields.tax_id'))->maxLength(20),
                    TextInput::make('trade_register')->label(__('customers.fields.trade_register'))->maxLength(30),
                    Toggle::make('requires_po_number')->label(__('customers.fields.requires_po_number')),
                ]),
            Section::make(__('customers.sections.credit'))->columns(3)
                ->description(__('customers.sections.credit_help'))
                ->visible(fn () => self::canManageCredit())
                ->schema([
                    Select::make('price_level')->label(__('customers.fields.price_level'))->options(PriceLevel::class)
                        ->default(PriceLevel::Retail)->required(),
                    Fields::money('credit_limit_cents', __('customers.fields.credit_limit')),
                    TextInput::make('payment_terms_days')->label(__('customers.fields.payment_terms_days'))->integer()->minValue(0)->suffix(__('customers.days')),
                ]),
            Section::make(__('app.more_details'))->collapsed()->columns(2)->schema([
                TextInput::make('email')->label(__('customers.fields.email'))->email(),
                TextInput::make('city')->label(__('customers.fields.city')),
                TextInput::make('address')->label(__('customers.fields.address'))->columnSpanFull(),
                Textarea::make('notes')->label(__('customers.fields.notes'))->columnSpanFull(),
                Toggle::make('is_active')->label(__('app.active'))->default(true),
            ]),
        ]);
    }

    public static function infolist(Schema $schema): Schema
    {
        return $schema->components([
            Section::make()->columns(4)->schema([
                TextEntry::make('name')->label(__('customers.fields.name'))->weight('bold'),
                TextEntry::make('phone')->label(__('customers.fields.phone'))
                    ->url(fn (Customer $record) => $record->phone ? 'tel:'.$record->phone : null),
                TextEntry::make('type')->label(__('customers.fields.type'))->badge(),
                TextEntry::make('price_level')->label(__('customers.fields.price_level'))->badge(),
                TextEntry::make('ice')->label(__('customers.fields.ice'))->placeholder('—'),
                TextEntry::make('outstanding')->label(__('customers.fields.outstanding'))
                    ->state(fn (Customer $record) => Money::format($record->outstandingCents()))
                    ->color(fn (Customer $record) => $record->outstandingCents() > 0 ? 'danger' : null)->weight('bold'),
                TextEntry::make('credit_limit_cents')->label(__('customers.fields.credit_limit'))
                    ->formatStateUsing(fn ($state) => Money::format((int) $state))->placeholder(__('customers.no_limit')),
                TextEntry::make('city')->label(__('customers.fields.city'))->placeholder('—'),
                TextEntry::make('notes')->label(__('customers.fields.notes'))->placeholder('—')->columnSpanFull(),
            ]),
        ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->modifyQueryUsing(fn (Builder $query) => $query->with('vehicles'))
            ->defaultSort('name')
            ->searchable(false)
            ->columns([
                TextColumn::make('name')->label(__('customers.fields.name'))->weight('bold')->sortable()
                    ->searchable(query: fn (Builder $query, string $search) => CustomerLookup::apply($query, $search)),
                TextColumn::make('phone')->label(__('customers.fields.phone')),
                TextColumn::make('vehicles.plate')->label(__('customers.fields.plates'))->badge()->limitList(3)
                    ->formatStateUsing(fn ($state) => Normalize::plateDisplay($state)),
                TextColumn::make('type')->label(__('customers.fields.type'))->badge()->toggleable(),
                TextColumn::make('city')->label(__('customers.fields.city'))->toggleable(isToggledHiddenByDefault: true),
            ])
            ->filters([
                SelectFilter::make('type')->label(__('customers.fields.type'))->options(CustomerType::class),
            ])
            ->recordActions([ViewAction::make(), EditAction::make()]);
    }

    public static function getRelations(): array
    {
        return [VehiclesRelationManager::class, SalesRelationManager::class];
    }

    public static function getPages(): array
    {
        return [
            'index' => ListCustomers::route('/'),
            'create' => CreateCustomer::route('/create'),
            'view' => ViewCustomer::route('/{record}'),
            'edit' => EditCustomer::route('/{record}/edit'),
        ];
    }
}
