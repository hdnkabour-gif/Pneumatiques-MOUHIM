<?php

use App\Actions\Money\CustomerAccountActions;
use App\Actions\Sales\ConfirmSaleAction;
use App\Actions\Sales\SaveSaleDraftAction;
use App\Enums\RoleName;
use App\Enums\StockMovementType;
use App\Exceptions\BusinessRuleException;
use App\Models\Customer;
use App\Models\Payment;
use App\Models\Product;
use App\Services\Stock\StockService;
use Spatie\Activitylog\Models\Activity;

/*
 * #21: a RETRY of the same receipt (same operation key) must post once; two genuine
 * receipts of the same amount (different keys) must both post. Synthetic data only.
 */
beforeEach(function () {
    $this->seller = userWithRole(RoleName::Seller, 'M01');
    $tyre = Product::factory()->tyre()->create(['price_retail_cents' => 10000, 'price_pro_cents' => 10000]);
    app(StockService::class)->receive($tyre, site('M01'), 10, 5000, StockMovementType::PurchaseReceipt);
    $this->customer = Customer::factory()->company()->create();
    $draft = app(SaveSaleDraftAction::class)->execute(['site_id' => site('M01')->id, 'customer_id' => $this->customer->id, 'lines' => [['product_id' => $tyre->id, 'quantity' => 1]]], $this->seller);
    app(ConfirmSaleAction::class)->execute($draft, [], $this->seller); // debt 100.00
    $this->receive = fn (array $data, $user = null) => app(CustomerAccountActions::class)
        ->receive($this->customer->fresh(), site('M01'), ['method' => 'card', ...$data], $user ?? $this->seller);
    $this->receipts = fn () => Payment::query()->where('customer_id', $this->customer->id)->count();
    $this->audits = fn () => Activity::query()->where('event', 'payment_received')->count();
});

it('posts a retried receipt once: same key and same request return the same payment (1)', function () {
    $first = ($this->receive)(['amount_cents' => 3000, 'idempotency_key' => 'rcpt-test-0001']);
    $again = ($this->receive)(['amount_cents' => 3000, 'idempotency_key' => 'rcpt-test-0001']);

    expect($again->id)->toBe($first->id)
        ->and(($this->receipts)())->toBe(1)
        ->and(($this->audits)())->toBe(1)
        ->and($this->customer->fresh()->outstandingCents())->toBe(7000);
});

it('refuses the same key with a different request (2)', function () {
    ($this->receive)(['amount_cents' => 3000, 'idempotency_key' => 'rcpt-test-0002']);

    expect(fn () => ($this->receive)(['amount_cents' => 4000, 'idempotency_key' => 'rcpt-test-0002']))
        ->toThrow(BusinessRuleException::class);
    expect(($this->receipts)())->toBe(1)->and($this->customer->fresh()->outstandingCents())->toBe(7000);
});

it('keeps two genuine receipts of the same amount when their keys differ (3)', function () {
    ($this->receive)(['amount_cents' => 3000, 'idempotency_key' => 'rcpt-test-0003a']);
    ($this->receive)(['amount_cents' => 3000, 'idempotency_key' => 'rcpt-test-0003b']);

    expect(($this->receipts)())->toBe(2)->and($this->customer->fresh()->outstandingCents())->toBe(4000);
});

it('does not burn the key when an attempt is refused, so a corrected retry posts (5)', function () {
    expect(fn () => ($this->receive)(['amount_cents' => 50000, 'idempotency_key' => 'rcpt-test-0005']))
        ->toThrow(BusinessRuleException::class); // more than due: nothing stored
    expect(($this->receipts)())->toBe(0);

    ($this->receive)(['amount_cents' => 3000, 'idempotency_key' => 'rcpt-test-0005']);
    expect(($this->receipts)())->toBe(1)->and($this->customer->fresh()->outstandingCents())->toBe(7000);
});

it('does not let another user or site replay a key (6)', function () {
    ($this->receive)(['amount_cents' => 3000, 'idempotency_key' => 'rcpt-test-0006']);
    $otherSeller = userWithRole(RoleName::Seller, 'M01');
    $m02Seller = userWithRole(RoleName::Seller, 'M02');

    expect(fn () => ($this->receive)(['amount_cents' => 3000, 'idempotency_key' => 'rcpt-test-0006'], $otherSeller))
        ->toThrow(BusinessRuleException::class); // same key, different actor: conflict, not a silent replay
    expect(fn () => app(CustomerAccountActions::class)->receive($this->customer->fresh(), site('M01'), ['method' => 'card', 'amount_cents' => 3000, 'idempotency_key' => 'rcpt-test-0006'], $m02Seller))
        ->toThrow(BusinessRuleException::class); // no access to the site
    expect(($this->receipts)())->toBe(1);
});

it('keeps the old behaviour when no key is sent, and rejects malformed keys', function () {
    ($this->receive)(['amount_cents' => 1000]);
    ($this->receive)(['amount_cents' => 1000]);
    expect(($this->receipts)())->toBe(2);

    expect(fn () => ($this->receive)(['amount_cents' => 1000, 'idempotency_key' => 'bad key with spaces']))
        ->toThrow(BusinessRuleException::class);
});
