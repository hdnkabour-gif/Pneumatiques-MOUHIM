<?php

use App\Actions\Money\CustomerAccountActions;
use App\Actions\Sales\ConfirmSaleAction;
use App\Actions\Sales\SaveSaleDraftAction;
use App\Enums\RoleName;
use App\Exceptions\BusinessRuleException;
use App\Filament\Resources\Customers\Pages\ViewCustomer;
use App\Filament\Resources\Payments\Pages\ListPayments;
use App\Filament\Resources\Payments\PaymentResource;
use App\Models\Customer;
use App\Models\Payment;
use App\Models\Product;
use App\Services\Stock\StockService;
use Livewire\Livewire;

beforeEach(function () {
    $this->owner = owner();
    $this->seller = userWithRole(RoleName::Seller, 'M01');
    $this->manager = userWithRole(RoleName::SiteManager, 'M01');
    $this->accounts = app(CustomerAccountActions::class);
    $this->pro = Customer::factory()->company(10000000)->create();
    $this->product = Product::factory()->create(['price_retail_cents' => 100000, 'price_pro_cents' => 100000]);
    app(StockService::class)->receive($this->product, site('M01'), 50, 50000);
    $this->creditSale = function (int $qty) {
        $d = app(SaveSaleDraftAction::class)->execute(['site_id' => site('M01')->id, 'customer_id' => $this->pro->id, 'lines' => [['product_id' => $this->product->id, 'quantity' => $qty]]], $this->owner);

        return app(ConfirmSaleAction::class)->execute($d, [], $this->owner);
    };
});

it('runs the §21 credit scenario with an opening balance and a later payment', function () {
    // Pro customer, limit 10 000 MAD, opening balance 3 000 MAD (owner only).
    expect(fn () => $this->accounts->addOpeningBalance($this->pro, 300000, '2026-10-31', 'Cahier', $this->seller))->toThrow(BusinessRuleException::class);
    $this->accounts->addOpeningBalance($this->pro, 300000, '2026-10-31', 'Cahier', $this->owner);

    $first = ($this->creditSale)(6);  // 6 000 on credit
    $second = ($this->creditSale)(1); // 1 000 on credit
    expect($this->pro->fresh()->outstandingCents())->toBe(1000000);

    // 3 500 received: opening balance first (3 000), then the oldest invoice (500).
    $this->accounts->receive($this->pro, site('M01'), ['method' => 'cash', 'amount_cents' => 350000], $this->seller);
    expect($this->pro->fresh()->outstandingCents())->toBe(650000)
        ->and($this->pro->openingBalances()->first()->remaining_cents)->toBe(0)
        ->and($first->fresh()->credit_cents)->toBe(550000)
        ->and($second->fresh()->credit_cents)->toBe(100000);

    expect(fn () => $this->accounts->receive($this->pro, site('M01'), ['method' => 'cash', 'amount_cents' => 700000], $this->seller))
        ->toThrow(BusinessRuleException::class);
});

it('restores the debt when a cheque is rejected, keeping its history (D-22)', function () {
    $sale = ($this->creditSale)(2);
    $cheque = $this->accounts->receive($this->pro, site('M01'), ['method' => 'cheque', 'amount_cents' => 200000, 'cheque_number' => '123', 'bank' => 'CIH'], $this->seller);
    expect($sale->fresh()->credit_cents)->toBe(0);

    expect(fn () => $this->accounts->chequeStatus($cheque, 'deposited', $this->seller))->toThrow(BusinessRuleException::class);
    $this->accounts->chequeStatus($cheque, 'deposited', $this->manager);
    expect(fn () => $this->accounts->chequeStatus($cheque->fresh(), 'rejected', $this->manager, ''))->toThrow(BusinessRuleException::class);
    $this->accounts->chequeStatus($cheque->fresh(), 'rejected', $this->manager, 'Sans provision');

    expect($sale->fresh()->credit_cents)->toBe(200000)
        ->and($this->pro->fresh()->outstandingCents())->toBe(200000)
        ->and(Payment::query()->find($cheque->id)->status)->toBe('rejected')
        ->and(Payment::query()->find($cheque->id)->rejection_reason)->toBe('Sans provision');
});

it('collects from the customer page and follows cheques from the payments list', function () {
    ($this->creditSale)(1);
    $this->seller->update(['default_site_id' => site('M01')->id]);
    $this->actingAs($this->seller);

    Livewire::test(ViewCustomer::class, ['record' => $this->pro->id])
        ->callAction('receive', ['method' => 'cheque', 'amount_cents' => '1000', 'cheque_number' => '555', 'bank' => 'BP'])
        ->assertNotified(__('money.done'));
    expect($this->pro->fresh()->outstandingCents())->toBe(0);

    $this->get(PaymentResource::getUrl())->assertForbidden();

    $this->actingAs($this->manager);
    $cheque = Payment::query()->where('cheque_number', '555')->firstOrFail();
    Livewire::test(ListPayments::class)->assertCanSeeTableRecords([$cheque])->callTableAction('deposited', $cheque);
    expect($cheque->fresh()->status)->toBe('deposited');
});
