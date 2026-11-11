<?php

namespace App\Filament\Resources\Suppliers;

use App\Actions\Purchases\PurchaseActions;
use App\Enums\Permission;
use App\Filament\Resources\Suppliers\Pages\ManageSuppliers;
use App\Filament\Support\Fields;
use App\Filament\Support\PageHub;
use App\Models\Supplier;
use App\Support\Money;
use BackedEnum;
use Filament\Actions\Action;
use Filament\Actions\CreateAction;
use Filament\Actions\EditAction;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Notifications\Notification;
use Filament\Resources\Resource;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;

/** Suppliers with what we owe them (§13). */
class SupplierResource extends Resource
{
    public static function shouldRegisterNavigation(): bool
    {
        return PageHub::inMenu(static::class) && parent::shouldRegisterNavigation();
    }

    protected static ?string $model = Supplier::class;

    protected static bool $hasTitleCaseModelLabel = false;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedBuildingStorefront;

    protected static ?int $navigationSort = 10;

    public static function canViewAny(): bool
    {
        return auth()->user()?->canAny([Permission::PurchasesManage->value, Permission::SuppliersPay->value]) ?? false;
    }

    public static function canCreate(): bool
    {
        return auth()->user()?->can(Permission::PurchasesManage->value) ?? false;
    }

    public static function canEdit($record): bool
    {
        return self::canCreate();
    }

    public static function canDelete($record): bool
    {
        return false;
    }

    public static function getModelLabel(): string
    {
        return __('purchases.supplier');
    }

    public static function getPluralModelLabel(): string
    {
        return __('purchases.suppliers');
    }

    public static function getNavigationGroup(): ?string
    {
        return __('app.navigation.purchases');
    }

    public static function form(Schema $schema): Schema
    {
        return $schema->columns(2)->components([
            TextInput::make('name')->label(__('purchases.fields.name'))->required()->maxLength(255),
            TextInput::make('phone')->label(__('purchases.fields.phone'))->tel(),
            Section::make(__('app.more_details'))->collapsed()->columns(2)->columnSpanFull()->schema([
                TextInput::make('ice')->label(__('purchases.fields.ice'))->maxLength(20),
                TextInput::make('email')->label(__('purchases.fields.email'))->email(),
                TextInput::make('city')->label(__('purchases.fields.city')),
                TextInput::make('address')->label(__('purchases.fields.address')),
                TextInput::make('payment_terms_days')->label(__('purchases.fields.payment_terms_days'))->integer()->minValue(0),
                Fields::money('opening_balance_cents', __('purchases.fields.opening_balance'))
                    ->visible(fn () => auth()->user()->can(Permission::ApprovalsDecide->value)),
                Textarea::make('notes')->label(__('purchases.fields.notes'))->columnSpanFull(),
                Toggle::make('is_active')->label(__('app.active'))->default(true),
            ]),
        ]);
    }

    public static function table(Table $table): Table
    {
        return $table->defaultSort('name')
            ->columns([
                TextColumn::make('name')->label(__('purchases.fields.name'))->weight('bold')->searchable(),
                TextColumn::make('phone')->label(__('purchases.fields.phone')),
                TextColumn::make('balance')->label(__('purchases.fields.balance'))
                    ->state(fn (Supplier $record) => Money::format($record->balanceCents()))
                    ->color(fn (Supplier $record) => $record->balanceCents() > 0 ? 'danger' : null),
            ])
            ->headerActions([CreateAction::make()])
            ->recordActions([
                Action::make('pay')->label(__('purchases.actions.pay'))->icon(Heroicon::OutlinedBanknotes)->color('success')
                    ->visible(fn () => auth()->user()->can(Permission::SuppliersPay->value))
                    ->schema([
                        Fields::money('amount_cents', __('purchases.fields.amount'))->required(),
                        Select::make('method')->label(__('purchases.fields.method'))->required()->default('transfer')
                            ->options(['cash' => __('enums.payment_method.cash'), 'cheque' => __('enums.payment_method.cheque'), 'transfer' => __('enums.payment_method.transfer')]),
                        TextInput::make('reference')->label(__('purchases.fields.reference')),
                    ])
                    ->action(function (Supplier $record, array $data) {
                        app(PurchaseActions::class)->pay($record, (int) $data['amount_cents'], $data['method'], $data['reference'] ?? null, auth()->user());
                        Notification::make()->success()->title(__('money.done'))->send();
                    }),
                EditAction::make(),
            ]);
    }

    public static function getPages(): array
    {
        return ['index' => ManageSuppliers::route('/')];
    }
}
