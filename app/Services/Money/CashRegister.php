<?php

namespace App\Services\Money;

use App\Enums\PaymentMethod;
use App\Enums\Permission;
use App\Enums\RefundMethod;
use App\Enums\SaleKind;
use App\Enums\SaleStatus;
use App\Exceptions\BusinessRuleException;
use App\Models\CashDay;
use App\Models\Expense;
use App\Models\Payment;
use App\Models\Sale;
use App\Models\Site;
use App\Models\User;
use App\Services\SettingsService;
use App\Support\Money;
use Carbon\CarbonImmutable;
use Filament\Notifications\Notification;
use Illuminate\Support\Facades\DB;

/**
 * Cash of a site for a day (§12, D-29): float + cash received − cash given
 * back − cash expenses = expected; the counted amount closes the day, which
 * then refuses any further cash operation until the owner reopens it.
 */
class CashRegister
{
    public function today(): CarbonImmutable
    {
        return CarbonImmutable::now(config('mouhim.timezone'))->startOfDay();
    }

    public function day(Site $site, ?CarbonImmutable $date = null): CashDay
    {
        return CashDay::query()->firstOrCreate(['site_id' => $site->id, 'business_date' => ($date ?? $this->today())->toDateString()]);
    }

    /** Refuses a cash operation on a closed day (called by every action that moves cash). */
    public function assertOpen(int $siteId): void
    {
        if (CashDay::query()->where('site_id', $siteId)->where('business_date', $this->today()->toDateString())->whereNotNull('closed_at')->exists()) {
            throw BusinessRuleException::make('cash.errors.closed');
        }
    }

    /** @return array{float: int, cash_in: int, refunds: int, expenses: int, expected: int, card: int, cheque: int} */
    public function summary(Site $site, ?CarbonImmutable $date = null): array
    {
        $date ??= $this->today();
        [$from, $to] = [$date->startOfDay()->utc(), $date->endOfDay()->utc()];
        $payments = Payment::query()->where('site_id', $site->id)->whereBetween('received_at', [$from, $to])
            ->where('status', '!=', 'rejected')->get()->groupBy(fn ($p) => $p->method->value);
        $refunds = (int) Sale::query()->where('site_id', $site->id)->where('kind', SaleKind::CreditNote->value)
            ->where('status', SaleStatus::Confirmed->value)->where('refund_method', RefundMethod::Cash->value)
            ->whereBetween('confirmed_at', [$from, $to])->sum('total_ttc_cents');
        $expenses = (int) Expense::query()->where('site_id', $site->id)->where('business_date', $date->toDateString())
            ->where('paid_in_cash', true)->sum('amount_cents');
        $float = $this->day($site, $date)->opening_float_cents;
        $cashIn = (int) ($payments[PaymentMethod::Cash->value] ?? collect())->sum('amount_cents');

        return [
            'float' => $float, 'cash_in' => $cashIn, 'refunds' => $refunds, 'expenses' => $expenses,
            'expected' => $float + $cashIn - $refunds - $expenses,
            'card' => (int) ($payments[PaymentMethod::Card->value] ?? collect())->sum('amount_cents'),
            'cheque' => (int) ($payments[PaymentMethod::Cheque->value] ?? collect())->sum('amount_cents'),
        ];
    }

    public function setFloat(Site $site, int $cents, User $user): void
    {
        $this->authorize($user, $site, Permission::CashClose);
        $this->assertOpen($site->id);
        $this->day($site)->update(['opening_float_cents' => max(0, $cents)]);
    }

    /** @param  array{category: string, amount_cents: int, description: string, paid_in_cash?: bool, photo?: ?string}  $data */
    public function addExpense(Site $site, array $data, User $user): Expense
    {
        $this->authorize($user, $site, Permission::ExpensesCreate);
        if ((int) $data['amount_cents'] <= 0 || trim((string) $data['description']) === '') {
            throw BusinessRuleException::make('cash.errors.expense');
        }
        if ($data['paid_in_cash'] ?? true) {
            $this->assertOpen($site->id);
        }

        $expense = Expense::query()->create([
            'site_id' => $site->id, 'business_date' => $this->today()->toDateString(), 'category' => $data['category'],
            'amount_cents' => (int) $data['amount_cents'], 'paid_in_cash' => (bool) ($data['paid_in_cash'] ?? true),
            'description' => trim($data['description']), 'photo' => $data['photo'] ?? null, 'user_id' => $user->id,
        ]);
        activity('cash')->causedBy($user)->performedOn($expense)->event('expense')
            ->withProperties(['amount_cents' => $expense->amount_cents, 'category' => $expense->category])
            ->tap(fn ($a) => $a->site_id = $site->id)->log('expense');

        return $expense;
    }

    /** Closing: counted cash, difference recorded; a big difference alerts the owner. */
    public function close(Site $site, int $countedCents, ?string $notes, User $user): CashDay
    {
        $this->authorize($user, $site, Permission::CashClose);

        return DB::transaction(function () use ($site, $countedCents, $notes, $user) {
            $day = CashDay::query()->lockForUpdate()->findOrFail($this->day($site)->id);
            if ($day->isClosed()) {
                throw BusinessRuleException::make('cash.errors.closed');
            }
            $expected = $this->summary($site)['expected'];
            $day->update(['expected_cents' => $expected, 'counted_cents' => $countedCents, 'difference_cents' => $countedCents - $expected,
                'notes' => $notes, 'closed_by' => $user->id, 'closed_at' => now()]);

            activity('cash')->causedBy($user)->performedOn($day)->event('cash_closed')
                ->withProperties(['expected' => $expected, 'counted' => $countedCents])->tap(fn ($a) => $a->site_id = $site->id)->log('cash_closed');

            if (abs($day->difference_cents) > (int) app(SettingsService::class)->get('cash.discrepancy_threshold_cents')) {
                foreach (User::query()->where('is_active', true)->get()->filter(fn (User $u) => $u->can(Permission::ApprovalsDecide->value)) as $owner) {
                    Notification::make()->warning()->title(__('cash.discrepancy', ['site' => $site->code, 'amount' => Money::format($day->difference_cents)]))
                        ->sendToDatabase($owner);
                }
            }

            return $day;
        });
    }

    /** The owner reopens a closed day, with a reason (D-29). */
    public function reopen(Site $site, string $reason, User $user): void
    {
        if (trim($reason) === '') {
            throw BusinessRuleException::make('approvals.errors.reason_required');
        }
        if (! $user->can(Permission::ApprovalsDecide->value)) {
            throw BusinessRuleException::make('app.errors.forbidden');
        }
        $day = $this->day($site);
        $day->update(['closed_at' => null, 'closed_by' => null, 'counted_cents' => null, 'difference_cents' => null, 'expected_cents' => null]);
        activity('cash')->causedBy($user)->performedOn($day)->event('cash_reopened')->withProperties(['reason' => $reason])
            ->tap(fn ($a) => $a->site_id = $site->id)->log('cash_reopened');
    }

    private function authorize(User $user, Site $site, Permission $permission): void
    {
        if (! $user->can($permission->value) || ! $user->canAccessSite($site)) {
            throw BusinessRuleException::make('app.errors.forbidden');
        }
    }
}
