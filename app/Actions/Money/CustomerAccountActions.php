<?php

namespace App\Actions\Money;

use App\Enums\PaymentMethod;
use App\Enums\Permission;
use App\Enums\SaleKind;
use App\Enums\SaleStatus;
use App\Exceptions\BusinessRuleException;
use App\Models\Customer;
use App\Models\CustomerOpeningBalance;
use App\Models\Payment;
use App\Models\Sale;
use App\Models\Site;
use App\Models\User;
use App\Services\Money\CashRegister;
use App\Support\Money;
use Illuminate\Support\Facades\DB;

/**
 * Customer account (D-20, D-22, D-23): opening balance, payment collected
 * after the sale and spread over the oldest debts first, cheque follow-up.
 * Nothing is deleted: a rejected cheque restores the debt and keeps its history.
 */
class CustomerAccountActions
{
    /**
     * D-23: debt from the paper books, entered by the owner only.
     *
     * #19 (PM-04): the balance and its audit entry are ONE transaction: if the audit cannot be
     * written, nothing is recorded, so a retry cannot duplicate the debt. An optional operation
     * key (sent by the form) makes a resubmission return the balance already recorded; the same
     * key with different values is refused. Lock order as receive(): the customer row.
     */
    public function addOpeningBalance(Customer $customer, int $amountCents, string $asOf, ?string $note, User $user, ?string $idempotencyKey = null): CustomerOpeningBalance
    {
        if (! $user->can(Permission::ApprovalsDecide->value)) {
            throw BusinessRuleException::make('app.errors.forbidden');
        }
        if ($amountCents <= 0) {
            throw BusinessRuleException::make('money.errors.amount');
        }
        $key = $idempotencyKey !== null && $idempotencyKey !== '' ? $idempotencyKey : null;
        if ($key !== null && ! preg_match('/^[A-Za-z0-9_-]{8,100}$/', $key)) {
            throw BusinessRuleException::make('money.errors.idempotency_key');
        }
        $fingerprint = $key === null ? null : hash('sha256', json_encode([
            'customer' => $customer->id, 'user' => $user->id, 'amount' => $amountCents, 'as_of' => $asOf, 'note' => $note,
        ]));

        return DB::transaction(function () use ($customer, $amountCents, $asOf, $note, $user, $key, $fingerprint) {
            if ($key !== null) {
                DB::select('SELECT pg_advisory_xact_lock(hashtextextended(?, 23))', [$key]);
                $previous = CustomerOpeningBalance::query()->where('idempotency_key', $key)->first();
                if ($previous) {
                    if (hash_equals((string) $previous->idempotency_fingerprint, $fingerprint)) {
                        return $previous;
                    }
                    throw BusinessRuleException::make('money.errors.idempotency_conflict');
                }
            }
            Customer::query()->lockForUpdate()->findOrFail($customer->id);

            $balance = $customer->openingBalances()->create([
                'amount_cents' => $amountCents, 'remaining_cents' => $amountCents, 'as_of' => $asOf, 'note' => $note, 'created_by' => $user->id,
                'idempotency_key' => $key, 'idempotency_fingerprint' => $fingerprint,
            ]);
            activity('money')->causedBy($user)->performedOn($customer)->event('opening_balance')
                ->withProperties(['amount_cents' => $amountCents, 'as_of' => $asOf])->log('opening_balance');

            return $balance;
        });
    }

    /**
     * @param  array{method: string, amount_cents: int, cheque_number?: ?string, bank?: ?string, drawer?: ?string, due_date?: ?string}  $data
     */
    public function receive(Customer $customer, Site $site, array $data, User $user): Payment
    {
        if (! $user->can(Permission::PaymentsReceive->value) || ! $user->canAccessSite($site)) {
            throw BusinessRuleException::make('app.errors.forbidden');
        }
        $method = PaymentMethod::tryFrom((string) ($data['method'] ?? ''));
        $amount = (int) ($data['amount_cents'] ?? 0);
        if (! $method || $method === PaymentMethod::CreditNote || $amount <= 0) {
            throw BusinessRuleException::make('money.errors.amount');
        }
        if ($method === PaymentMethod::Cheque && (blank($data['cheque_number'] ?? null) || blank($data['bank'] ?? null))) {
            throw BusinessRuleException::make('sales.errors.cheque_details');
        }

        // #21: optional operation key. Same key + same request = the same receipt (a retry);
        // same key + different request = conflict. No key = previous behaviour.
        $key = isset($data['idempotency_key']) && $data['idempotency_key'] !== '' ? (string) $data['idempotency_key'] : null;
        if ($key !== null && ! preg_match('/^[A-Za-z0-9_-]{8,100}$/', $key)) {
            throw BusinessRuleException::make('money.errors.idempotency_key');
        }
        $fingerprint = $key === null ? null : hash('sha256', json_encode([
            'customer' => $customer->id, 'site' => $site->id, 'user' => $user->id, 'method' => $method->value, 'amount' => $amount,
            'cheque_number' => $method === PaymentMethod::Cheque ? trim((string) ($data['cheque_number'] ?? '')) : null,
            'bank' => $method === PaymentMethod::Cheque ? trim((string) ($data['bank'] ?? '')) : null,
            'drawer' => $method === PaymentMethod::Cheque ? trim((string) ($data['drawer'] ?? '')) : null,
            'due_date' => $method === PaymentMethod::Cheque ? ($data['due_date'] ?? null) : null,
        ]));

        return DB::transaction(function () use ($customer, $site, $data, $user, $method, $amount, $key, $fingerprint) {
            if ($key !== null) {
                // Serialise every request carrying this key (even for another customer),
                // then return the earlier receipt or refuse a different request.
                DB::select('SELECT pg_advisory_xact_lock(hashtextextended(?, 21))', [$key]);
                $previous = Payment::query()->where('idempotency_key', $key)->first();
                if ($previous) {
                    if (hash_equals((string) $previous->idempotency_fingerprint, $fingerprint)) {
                        return $previous;
                    }
                    throw BusinessRuleException::make('money.errors.idempotency_conflict');
                }
            }
            // D-29: a NEW cash receipt needs an open cash day. Checked after the replay lookup on
            // purpose (#21 review): a retry of a receipt that was already recorded must return it
            // even if the day has been closed since; a new posting on a closed day is still refused.
            if ($method === PaymentMethod::Cash) {
                app(CashRegister::class)->assertOpen($site->id);
            }
            Customer::query()->lockForUpdate()->findOrFail($customer->id);
            if ($amount > $customer->outstandingCents()) {
                throw BusinessRuleException::make('money.errors.more_than_due', ['due' => Money::format($customer->outstandingCents())]);
            }

            $payment = Payment::query()->create([
                'site_id' => $site->id, 'customer_id' => $customer->id, 'method' => $method, 'amount_cents' => $amount,
                'cheque_number' => $method === PaymentMethod::Cheque ? trim($data['cheque_number']) : null,
                'bank' => $method === PaymentMethod::Cheque ? trim($data['bank']) : null,
                'drawer' => $method === PaymentMethod::Cheque ? ($data['drawer'] ?? null) : null,
                'due_date' => $method === PaymentMethod::Cheque ? ($data['due_date'] ?? null) : null,
                'status' => 'received', 'received_by' => $user->id, 'received_at' => now(),
                'idempotency_key' => $key, 'idempotency_fingerprint' => $fingerprint,
            ]);

            // Oldest first: opening balance, then documents by date.
            $left = $amount;
            foreach ($customer->openingBalances()->where('remaining_cents', '>', 0)->lockForUpdate()->get() as $ob) {
                $take = min($left, $ob->remaining_cents);
                DB::table('payment_allocations')->insert(['payment_id' => $payment->id, 'opening_balance_id' => $ob->id, 'amount_cents' => $take]);
                $ob->decrement('remaining_cents', $take);
                if (($left -= $take) === 0) {
                    break;
                }
            }
            if ($left > 0) {
                $docs = Sale::query()->where('customer_id', $customer->id)->where('status', SaleStatus::Confirmed->value)
                    ->whereIn('kind', [SaleKind::Invoice->value, SaleKind::DeliveryNote->value])->whereNull('recap_invoice_id')
                    ->where('credit_cents', '>', 0)->orderBy('confirmed_at')->orderBy('id')->lockForUpdate()->get();
                foreach ($docs as $doc) {
                    $take = min($left, $doc->credit_cents);
                    DB::table('payment_allocations')->insert(['payment_id' => $payment->id, 'sale_id' => $doc->id, 'amount_cents' => $take]);
                    $doc->forceFill(['credit_cents' => $doc->credit_cents - $take, 'paid_cents' => $doc->paid_cents + $take])->save();
                    if (($left -= $take) === 0) {
                        break;
                    }
                }
            }
            // What is left covers credit notes kept on account: nothing to allocate, the balance already counts them.

            activity('money')->causedBy($user)->performedOn($payment)->event('payment_received')
                ->withProperties(['customer' => $customer->id, 'amount_cents' => $amount, 'method' => $method->value])
                ->tap(fn ($a) => $a->site_id = $site->id)->log('payment_received');

            return $payment;
        });
    }

    /** Cheque follow-up: received → deposited → cashed, or rejected (debt restored). */
    public function chequeStatus(Payment $payment, string $status, User $user, ?string $reason = null): Payment
    {
        if (! $user->can(Permission::ChequesManage->value) || ! $user->canAccessSite($payment->site_id)) {
            throw BusinessRuleException::make('app.errors.forbidden');
        }
        if ($status === 'rejected' && blank($reason)) {
            throw BusinessRuleException::make('approvals.errors.reason_required');
        }

        return DB::transaction(function () use ($payment, $status, $user, $reason) {
            $payment = Payment::query()->lockForUpdate()->findOrFail($payment->id);
            $allowed = ['received' => ['deposited', 'rejected'], 'deposited' => ['cleared', 'rejected'], 'cleared' => [], 'rejected' => []];
            if ($payment->method !== PaymentMethod::Cheque || ! in_array($status, $allowed[$payment->status] ?? [], true)) {
                throw BusinessRuleException::make('money.errors.cheque_status');
            }

            if ($status === 'rejected') {
                foreach (DB::table('payment_allocations')->where('payment_id', $payment->id)->get() as $a) {
                    if ($a->sale_id) {
                        $sale = Sale::query()->lockForUpdate()->find($a->sale_id);
                        $sale->forceFill(['credit_cents' => $sale->credit_cents + $a->amount_cents, 'paid_cents' => $sale->paid_cents - $a->amount_cents])->save();
                    } else {
                        CustomerOpeningBalance::query()->whereKey($a->opening_balance_id)->increment('remaining_cents', $a->amount_cents);
                    }
                }
            }

            $payment->forceFill(['status' => $status, $status.'_at' => now(), 'rejection_reason' => $status === 'rejected' ? trim($reason) : null])->save();

            activity('money')->causedBy($user)->performedOn($payment)->event('cheque_'.$status)
                ->withProperties(['reason' => $reason])->tap(fn ($a) => $a->site_id = $payment->site_id)->log('cheque_'.$status);

            return $payment;
        });
    }
}
