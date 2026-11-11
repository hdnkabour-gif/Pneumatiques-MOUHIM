<?php

namespace App\Filament\Resources\Payments;

use App\Actions\Money\CustomerAccountActions;
use App\Enums\PaymentMethod;
use App\Enums\Permission;
use App\Exceptions\BusinessRuleException;
use App\Filament\Resources\Payments\Pages\ListPayments;
use App\Filament\Support\Fields;
use App\Filament\Support\PageHub;
use App\Models\Payment;
use App\Models\Site;
use BackedEnum;
use Filament\Actions\Action;
use Filament\Forms\Components\TextInput;
use Filament\Notifications\Notification;
use Filament\Resources\Resource;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;

/** Money received; cheques followed until cashed or rejected (D-22). Read-only otherwise. */
class PaymentResource extends Resource
{
    public static function shouldRegisterNavigation(): bool
    {
        return PageHub::inMenu(static::class) && parent::shouldRegisterNavigation();
    }

    protected static ?string $model = Payment::class;

    protected static bool $hasTitleCaseModelLabel = false;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedBanknotes;

    protected static ?int $navigationSort = 25;

    public static function canViewAny(): bool
    {
        return auth()->user()?->canAny([Permission::ChequesManage->value, Permission::ApprovalsDecide->value]) ?? false;
    }

    public static function canCreate(): bool
    {
        return false;
    }

    public static function getModelLabel(): string
    {
        return __('money.payments');
    }

    public static function getPluralModelLabel(): string
    {
        return __('money.payments');
    }

    public static function getNavigationGroup(): ?string
    {
        return __('app.navigation.sales');
    }

    public static function getEloquentQuery(): Builder
    {
        $user = auth()->user();

        return parent::getEloquentQuery()->with(['customer', 'site'])
            ->when(! $user->canSeeAllSites(), fn (Builder $query) => $query->whereIn('site_id', $user->siteIds()));
    }

    public static function table(Table $table): Table
    {
        $move = fn (string $to) => Action::make($to)->label(__('money.actions.'.$to))
            ->color($to === 'rejected' ? 'danger' : ($to === 'cleared' ? 'success' : 'gray'))
            ->visible(fn (Payment $record) => $record->method === PaymentMethod::Cheque
                && in_array($to, ['received' => ['deposited', 'rejected'], 'deposited' => ['cleared', 'rejected']][$record->status] ?? [], true))
            ->schema($to === 'rejected' ? [TextInput::make('reason')->label(__('money.reason'))->required()] : [])
            ->requiresConfirmation($to !== 'rejected')
            ->action(function (Payment $record, array $data) use ($to) {
                try {
                    app(CustomerAccountActions::class)->chequeStatus($record, $to, auth()->user(), $data['reason'] ?? null);
                } catch (BusinessRuleException $e) {
                    Notification::make()->danger()->title($e->getMessage())->send();
                }
            });

        return $table
            ->defaultSort('received_at', 'desc')
            ->columns([
                TextColumn::make('received_at')->label(__('sales.fields.date'))->dateTime('d/m/Y H:i')->sortable(),
                TextColumn::make('customer.name')->label(__('sales.fields.customer'))->placeholder(__('sales.walk_in'))->searchable(),
                TextColumn::make('method')->label(__('money.method'))->badge(),
                Fields::moneyColumn('amount_cents', __('money.amount')),
                TextColumn::make('cheque_number')->label(__('sales.fields.cheque_number'))->placeholder('—')
                    ->description(fn (Payment $record) => $record->bank),
                TextColumn::make('due_date')->label(__('sales.fields.due_date'))->date('d/m/Y')->placeholder('—'),
                TextColumn::make('status')->label(__('sales.fields.status'))->badge()
                    ->formatStateUsing(fn ($state) => __('money.cheque_status.'.$state))
                    ->color(fn ($state) => match ($state) {
                        'rejected' => 'danger', 'cleared' => 'success', 'deposited' => 'info', default => 'gray'
                    }),
                TextColumn::make('site.code')->label(__('sales.fields.site'))->badge(),
            ])
            ->filters([
                SelectFilter::make('method')->label(__('money.method'))->options(PaymentMethod::class)->default(PaymentMethod::Cheque->value),
                SelectFilter::make('status')->label(__('sales.fields.status'))->options(__('money.cheque_status')),
                SelectFilter::make('site_id')->label(__('sales.fields.site'))
                    ->options(fn () => Site::query()->visibleTo(auth()->user())->orderBy('code')->pluck('name', 'id')),
            ])
            ->recordActions([$move('deposited'), $move('cleared'), $move('rejected')]);
    }

    public static function getPages(): array
    {
        return ['index' => ListPayments::route('/')];
    }
}
