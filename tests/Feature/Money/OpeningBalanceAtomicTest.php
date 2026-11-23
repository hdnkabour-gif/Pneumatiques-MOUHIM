<?php

use App\Actions\Money\CustomerAccountActions;
use App\Exceptions\BusinessRuleException;
use App\Models\Activity;
use App\Models\Customer;
use App\Models\CustomerOpeningBalance;

/*
 * #19 (PM-04): an opening balance (debt from the paper books) and its audit entry are ONE
 * operation. If the audit cannot be written, nothing is recorded, so a retry cannot create a
 * duplicate. A form resubmission carrying the same operation key records the balance once.
 */
beforeEach(function () {
    $this->owner = owner();
    $this->customer = Customer::factory()->company()->create();
    $this->add = fn (int $cents = 50000, ?string $key = null, string $asOf = '2026-11-01') => app(CustomerAccountActions::class)
        ->addOpeningBalance($this->customer, $cents, $asOf, 'TEST ardoise papier', $this->owner, $key);
});

it('records nothing when the audit entry cannot be written, and a retry records exactly once', function () {
    Activity::creating(function (Activity $a) {
        if ($a->event === 'opening_balance') {
            throw new RuntimeException('TEST audit store unavailable');
        }
    });
    expect(fn () => ($this->add)())->toThrow(RuntimeException::class);
    expect(CustomerOpeningBalance::query()->count())->toBe(0)
        ->and($this->customer->fresh()->outstandingCents())->toBe(0);

    Activity::flushEventListeners();
    ($this->add)();
    expect(CustomerOpeningBalance::query()->count())->toBe(1)
        ->and(Activity::query()->where('event', 'opening_balance')->count())->toBe(1)
        ->and($this->customer->fresh()->outstandingCents())->toBe(50000);
});

it('records a resubmitted form once: same key and same values return the same balance', function () {
    $first = ($this->add)(50000, 'open-key-0001');
    $again = ($this->add)(50000, 'open-key-0001');

    expect($again->id)->toBe($first->id)
        ->and(CustomerOpeningBalance::query()->count())->toBe(1)
        ->and(Activity::query()->where('event', 'opening_balance')->count())->toBe(1)
        ->and($this->customer->fresh()->outstandingCents())->toBe(50000);
});

it('refuses the same key with different values, and keeps two genuine entries with different keys', function () {
    ($this->add)(50000, 'open-key-0002');
    expect(fn () => ($this->add)(60000, 'open-key-0002'))->toThrow(BusinessRuleException::class, __('money.errors.idempotency_conflict'));
    expect(fn () => ($this->add)(50000, 'open-key-0002', '2026-10-01'))->toThrow(BusinessRuleException::class);

    ($this->add)(50000, 'open-key-0003');
    expect(CustomerOpeningBalance::query()->count())->toBe(2)
        ->and($this->customer->fresh()->outstandingCents())->toBe(100000);
});

it('keeps the previous behaviour without a key, and rejects a malformed key', function () {
    ($this->add)(10000);
    ($this->add)(10000);
    expect(CustomerOpeningBalance::query()->count())->toBe(2);
    expect(fn () => ($this->add)(10000, 'bad key!'))->toThrow(BusinessRuleException::class, __('money.errors.idempotency_key'));
});
