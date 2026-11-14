<?php

use App\Actions\Money\CustomerAccountActions;
use App\Actions\Sales\ConfirmSaleAction;
use App\Actions\Sales\SaveSaleDraftAction;
use App\Enums\RefundMethod;
use App\Enums\SaleKind;
use App\Enums\SaleStatus;
use App\Exceptions\BusinessRuleException;
use App\Models\Customer;
use App\Models\Payment;
use App\Models\Product;
use App\Models\Sale;
use App\Models\StockMovement;
use App\Services\Money\CashRegister;
use App\Services\Sales\SaleDocuments;

it('checks the total drawn from each voucher before confirming the sale', function () {
    $user = owner();
    $site = site('M01');
    $customer = Customer::factory()->create();
    $product = Product::factory()->create(['price_retail_cents' => 16000]);
    $draft = app(SaveSaleDraftAction::class)->execute([
        'site_id' => $site->id, 'customer_id' => $customer->id, 'lines' => [['product_id' => $product->id]],
    ], $user);
    $voucher = Sale::query()->create([
        'kind' => SaleKind::CreditNote, 'status' => SaleStatus::Confirmed, 'number' => 'AV-TEST',
        'confirmed_at' => now(), 'confirmed_by' => $user->id, 'seller_id' => $user->id,
        'site_id' => $site->id, 'customer_id' => $customer->id, 'price_level' => $customer->price_level,
        'refund_method' => RefundMethod::Voucher, 'total_ttc_cents' => 10000,
    ]);
    $line = ['method' => 'credit_note', 'credit_note_id' => $voucher->id, 'amount_cents' => 8000];
    $movements = StockMovement::count();
    $payments = Payment::count();

    expect(fn () => app(ConfirmSaleAction::class)->execute($draft, [$line, $line], $user))
        ->toThrow(BusinessRuleException::class, __('sales.errors.voucher_invalid'));
    expect($draft->fresh()->isDraft())->toBeTrue()
        ->and($voucher->voucherBalanceCents())->toBe(10000)
        ->and(StockMovement::count())->toBe($movements)
        ->and(Payment::count())->toBe($payments);
});

it('keeps the issued invoice while later receipts update the customer and daily cash balances', function () {
    $this->travelTo(now()->setTime(12, 0));
    $user = owner();
    $site = site('M01');
    $customer = Customer::factory()->create(['credit_limit_cents' => 150000]);
    $service = Product::factory()->service()->create(['price_retail_cents' => 100000]);
    $draft = app(SaveSaleDraftAction::class)->execute([
        'site_id' => $site->id, 'customer_id' => $customer->id, 'lines' => [['product_id' => $service->id]],
    ], $user);
    $sale = app(ConfirmSaleAction::class)->execute($draft, [['method' => 'cash', 'amount_cents' => 20000]], $user);
    $documents = app(SaleDocuments::class);
    $pdf = $documents->pdf($sale);
    $register = app(CashRegister::class);
    $firstDay = $register->today();
    expect($pdf)->toStartWith('%PDF')->and($sale->credit_cents)->toBe(80000);

    $this->travel(1)->days();
    app(CustomerAccountActions::class)->receive($customer, $site, ['method' => 'cash', 'amount_cents' => 30000], $user);
    $customer->update(['name' => 'Nouveau nom', 'ice' => '123456789012345']);

    expect($customer->outstandingCents())->toBe(50000)
        ->and($register->summary($site, $firstDay)['cash_in'])->toBe(20000)
        ->and($register->summary($site)['cash_in'])->toBe(30000)
        ->and($documents->pdf($sale->fresh()))->toBe($pdf);
});
