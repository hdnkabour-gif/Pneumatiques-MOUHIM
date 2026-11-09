<?php

namespace App\Filament\Pages;

use App\Enums\Permission;
use App\Exceptions\BusinessRuleException;
use App\Filament\Support\Fields;
use App\Models\Expense;
use App\Models\Site;
use App\Services\Money\CashRegister;
use App\Support\Decimal;
use App\Support\Money;
use BackedEnum;
use Filament\Actions\Action;
use Filament\Forms\Components\FileUpload;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Notifications\Notification;
use Filament\Pages\Page;
use Filament\Support\Icons\Heroicon;
use Illuminate\Support\Collection;

/** Today's cash of a site (§12): what came in, went out, what should be in the drawer; closing locks the day (D-29). */
class CashDesk extends Page
{
    public const CATEGORIES = ['supplies', 'transport', 'food', 'repairs', 'utilities', 'salary_advance', 'other'];

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedCalculator;

    protected static ?int $navigationSort = 35;

    protected string $view = 'filament.pages.cash-desk';

    public ?int $siteId = null;

    public static function canAccess(): bool
    {
        return auth()->user()?->canAny([Permission::ExpensesCreate->value, Permission::CashClose->value]) ?? false;
    }

    public static function getNavigationGroup(): ?string
    {
        return __('app.navigation.sales');
    }

    public static function getNavigationLabel(): string
    {
        return __('cash.title');
    }

    public function getTitle(): string
    {
        return __('cash.title');
    }

    public function mount(): void
    {
        $user = auth()->user();
        $this->siteId = $user->default_site_id && $user->canAccessSite($user->default_site_id)
            ? $user->default_site_id : $this->sites()->first()?->id;
    }

    public function updatedSiteId(): void
    {
        abort_unless($this->siteId && auth()->user()->canAccessSite($this->siteId), 403);
    }

    public function sites(): Collection
    {
        return Site::query()->visibleTo(auth()->user())->where('is_active', true)->orderBy('code')->get();
    }

    public function site(): Site
    {
        return Site::query()->findOrFail($this->siteId);
    }

    public function summary(): array
    {
        return app(CashRegister::class)->summary($this->site());
    }

    public function day()
    {
        return app(CashRegister::class)->day($this->site());
    }

    public function expenses(): Collection
    {
        return Expense::query()->with('user')->where('site_id', $this->siteId)
            ->where('business_date', app(CashRegister::class)->today()->toDateString())->latest()->get();
    }

    public function money(?int $cents): string
    {
        return Money::format($cents);
    }

    protected function getHeaderActions(): array
    {
        $run = function (callable $callback, string $done) {
            try {
                $callback();
                Notification::make()->success()->title($done)->send();
            } catch (BusinessRuleException $e) {
                Notification::make()->danger()->title($e->getMessage())->send();
            }
        };

        return [
            Action::make('expense')->label(__('cash.expense'))->icon(Heroicon::OutlinedCamera)
                ->visible(fn () => auth()->user()->can(Permission::ExpensesCreate->value))
                ->schema([
                    Fields::money('amount_cents', __('money.amount'))->required(),
                    Select::make('category')->label(__('cash.category'))->required()->default('other')
                        ->options(collect(self::CATEGORIES)->mapWithKeys(fn ($c) => [$c => __('cash.categories.'.$c)])->all()),
                    TextInput::make('description')->label(__('cash.description'))->required()->maxLength(255),
                    FileUpload::make('photo')->label(__('cash.photo'))->image()->disk('public')->directory('expenses')->maxSize(5120),
                    Toggle::make('paid_in_cash')->label(__('cash.paid_in_cash'))->default(true),
                ])
                ->action(fn (array $data) => $run(fn () => app(CashRegister::class)->addExpense($this->site(), $data, auth()->user()), __('cash.saved'))),
            Action::make('float')->label(__('cash.float'))->icon(Heroicon::OutlinedBanknotes)->color('gray')
                ->visible(fn () => auth()->user()->can(Permission::CashClose->value) && ! $this->day()->isClosed())
                ->schema([Fields::money('cents', __('cash.float'))->required()->default(fn () => Decimal::fromCents($this->day()->opening_float_cents))])
                ->action(fn (array $data) => $run(fn () => app(CashRegister::class)->setFloat($this->site(), (int) $data['cents'], auth()->user()), __('cash.saved'))),
            Action::make('close')->label(__('cash.close'))->icon(Heroicon::OutlinedLockClosed)->color('success')
                ->visible(fn () => auth()->user()->can(Permission::CashClose->value) && ! $this->day()->isClosed())
                ->modalDescription(fn () => __('cash.expected').' : '.$this->money($this->summary()['expected']))
                ->schema([
                    Fields::money('counted', __('cash.counted'))->required(),
                    Textarea::make('notes')->label(__('cash.notes')),
                ])
                ->action(fn (array $data) => $run(fn () => app(CashRegister::class)->close($this->site(), (int) $data['counted'], $data['notes'] ?? null, auth()->user()), __('cash.closed'))),
            Action::make('reopen')->label(__('cash.reopen'))->icon(Heroicon::OutlinedLockOpen)->color('warning')
                ->visible(fn () => auth()->user()->can(Permission::ApprovalsDecide->value) && $this->day()->isClosed())
                ->schema([TextInput::make('reason')->label(__('sales.fields.reason'))->required()])
                ->action(fn (array $data) => $run(fn () => app(CashRegister::class)->reopen($this->site(), $data['reason'], auth()->user()), __('cash.saved'))),
        ];
    }
}
