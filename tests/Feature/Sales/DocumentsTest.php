<?php

use App\Actions\Sales\ConfirmSaleAction;
use App\Actions\Sales\CreateCreditNoteAction;
use App\Actions\Sales\CreateRecapInvoiceAction;
use App\Actions\Sales\QuoteActions;
use App\Actions\Sales\RequestSaleApprovalAction;
use App\Actions\Sales\SaveSaleDraftAction;
use App\Enums\ApprovalType;
use App\Enums\QuoteStatus;
use App\Enums\RefundMethod;
use App\Enums\RoleName;
use App\Enums\StockMovementType;
use App\Enums\UsedTyreStatus;
use App\Exceptions\ApprovalRequiredException;
use App\Exceptions\BusinessRuleException;
use App\Exceptions\InsufficientStockException;
use App\Models\ApprovalRequest;
use App\Models\Customer;
use App\Models\Product;
use App\Models\Sale;
use App\Models\StockLevel;
use App\Models\StockMovement;
use App\Models\TyreLot;
use App\Models\UsedTyre;
use App\Services\Approvals\ApprovalService;
use App\Services\Stock\LotInput;
use App\Services\Stock\StockService;

beforeEach(function () {
    $this->seller = userWithRole(RoleName::Seller, 'M01');
    $this->stock = app(StockService::class);
    $this->tyre = Product::factory()->tyre()->create(['price_retail_cents' => 80000, 'price_pro_cents' => 70000]);
    $this->stock->receive($this->tyre, site('M01'), 10, 50000);
    $this->draft = fn (array $lines, array $extra = [], $user = null) => app(SaveSaleDraftAction::class)
        ->execute(['site_id' => site('M01')->id, 'lines' => $lines, ...$extra], $user ?? $this->seller);
    $this->confirm = fn (Sale $sale, ?array $payments = null, $user = null, bool $reserve = false) => app(ConfirmSaleAction::class)
        ->execute($sale, $payments ?? [['method' => 'cash', 'amount_cents' => $sale->total_ttc_cents]], $user ?? $this->seller, [], $reserve);
    $this->level = fn () => StockLevel::query()->where(['product_id' => $this->tyre->id, 'site_id' => site('M01')->id])->first();
});

it('issues a quote with a number and a validity, without touching stock or money', function () {
    $quote = ($this->confirm)(($this->draft)([['product_id' => $this->tyre->id, 'quantity' => 4]], ['kind' => 'quote']), []);

    expect($quote->number)->toBe('DV-'.now()->year.'-000001')
        ->and($quote->quote_status)->toBe(QuoteStatus::Sent)
        ->and($quote->valid_until->toDateString())->toBe(today()->addDays(15)->toDateString())
        ->and($quote->reserved)->toBeFalse()
        ->and(($this->level)()->quantity)->toBe(10)
        ->and($quote->payments)->toHaveCount(0);
});

it('lets only the owner reserve stock on a quote, released at conversion (D-13)', function () {
    $owner = owner();
    $quote = ($this->confirm)(($this->draft)([['product_id' => $this->tyre->id, 'quantity' => 8]], ['kind' => 'quote'], $owner), [], $owner, true);
    expect($quote->reserved)->toBeTrue()->and(($this->level)()->reserved)->toBe(8);

    // Reserved tyres cannot be sold to someone else.
    expect(fn () => ($this->confirm)(($this->draft)([['product_id' => $this->tyre->id, 'quantity' => 4]])))->toThrow(InsufficientStockException::class);

    $sale = app(QuoteActions::class)->toDraft($quote, $this->seller);
    $sale = ($this->confirm)($sale);

    expect($sale->number)->toStartWith('FA-')
        ->and($quote->fresh()->quote_status)->toBe(QuoteStatus::Converted)
        ->and(($this->level)()->reserved)->toBe(0)
        ->and(($this->level)()->quantity)->toBe(2);

    // A seller's reservation request is ignored.
    $q2 = ($this->confirm)(($this->draft)([['product_id' => $this->tyre->id, 'quantity' => 1]], ['kind' => 'quote']), [], null, true);
    expect($q2->reserved)->toBeFalse();
});

it('keeps the quote price and its approvals when converted', function () {
    $quote = ($this->draft)([['product_id' => $this->tyre->id, 'quantity' => 1, 'discount_bp' => 1000]], ['kind' => 'quote']);
    expect(fn () => ($this->confirm)($quote, []))->toThrow(ApprovalRequiredException::class);
    [$request] = app(RequestSaleApprovalAction::class)->execute($quote, [ApprovalType::Discount], 'Fidèle', $this->seller);
    app(ApprovalService::class)->decide($request, owner(), true);
    $quote = ($this->confirm)($quote->fresh(), []);

    $this->tyre->update(['price_retail_cents' => 90000]); // price rise after the quote

    $sale = ($this->confirm)(app(QuoteActions::class)->toDraft($quote, $this->seller));
    expect($sale->total_ttc_cents)->toBe(72000);
});

it('refuses and expires quotes, releasing their reservation', function () {
    $owner = owner();
    $q1 = ($this->confirm)(($this->draft)([['product_id' => $this->tyre->id, 'quantity' => 2]], ['kind' => 'quote'], $owner), [], $owner, true);
    $q2 = ($this->confirm)(($this->draft)([['product_id' => $this->tyre->id, 'quantity' => 3]], ['kind' => 'quote'], $owner), [], $owner, true);
    expect(($this->level)()->reserved)->toBe(5);

    app(QuoteActions::class)->refuse($q1, $this->seller);
    $this->travel(16)->days();
    expect(app(QuoteActions::class)->expireOld())->toBe(1);

    expect($q1->fresh()->quote_status)->toBe(QuoteStatus::Refused)
        ->and($q2->fresh()->quote_status)->toBe(QuoteStatus::Expired)
        ->and(($this->level)()->reserved)->toBe(0)
        ->and(fn () => app(QuoteActions::class)->toDraft($q2->fresh(), $this->seller))->toThrow(BusinessRuleException::class);
});

it('delivers on BL / BT then groups the notes in one recap invoice (D-16)', function () {
    $pro = Customer::factory()->company(10000000)->create();
    $service = Product::factory()->service()->create();

    $bl = ($this->confirm)(($this->draft)([['product_id' => $this->tyre->id, 'quantity' => 2]], ['kind' => 'delivery_note', 'customer_id' => $pro->id]), []);
    $bt = ($this->confirm)(($this->draft)([['product_id' => $this->tyre->id, 'quantity' => 1], ['product_id' => $service->id, 'quantity' => 1]], ['kind' => 'delivery_note', 'customer_id' => $pro->id]), []);

    expect($bl->number)->toBe('BL-'.now()->year.'-000001')
        ->and($bt->number)->toBe('BT-'.now()->year.'-000001')
        ->and(($this->level)()->quantity)->toBe(7)
        ->and($pro->fresh()->outstandingCents())->toBe(140000 + 70000 + 5000);

    $invoice = app(CreateRecapInvoiceAction::class)->execute($pro, [$bl->id, $bt->id], $this->seller);

    expect($invoice->number)->toBe('FA-'.now()->year.'-000001')
        ->and($invoice->total_ttc_cents)->toBe(215000)
        ->and($invoice->lines)->toHaveCount(3)
        ->and($invoice->lines->first()->description)->toStartWith($bl->number)
        ->and($bl->fresh()->recap_invoice_id)->toBe($invoice->id)
        ->and($pro->fresh()->outstandingCents())->toBe(215000) // counted once
        ->and(($this->level)()->quantity)->toBe(7);            // no second stock exit

    expect(fn () => app(CreateRecapInvoiceAction::class)->execute($pro, [$bl->id], $this->seller))->toThrow(BusinessRuleException::class);
    expect(fn () => ($this->draft)([['product_id' => $this->tyre->id, 'quantity' => 1]], ['kind' => 'delivery_note']))->toThrow(BusinessRuleException::class);
});

it('checks the credit limit on delivery notes too', function () {
    $pro = Customer::factory()->company(100000)->create(); // 1 000 MAD
    $bl = ($this->draft)([['product_id' => $this->tyre->id, 'quantity' => 2]], ['kind' => 'delivery_note', 'customer_id' => $pro->id]);

    expect(fn () => ($this->confirm)($bl, []))->toThrow(ApprovalRequiredException::class);
});

it('returns goods to stock with a credit note asked by the seller and approved by the owner', function () {
    $customer = Customer::factory()->create();
    $invoice = ($this->confirm)(($this->draft)([['product_id' => $this->tyre->id, 'quantity' => 4]], ['customer_id' => $customer->id]));
    $line = $invoice->lines->first();

    $request = app(CreateCreditNoteAction::class)->request($invoice, [$line->id => ['quantity' => 1, 'restock' => true]], RefundMethod::Cash, 'Mauvaise dimension', $this->seller);
    expect($request)->toBeInstanceOf(ApprovalRequest::class)
        ->and(Sale::query()->where('kind', 'credit_note')->count())->toBe(0);

    app(ApprovalService::class)->decide($request, owner(), true);

    $note = Sale::query()->where('kind', 'credit_note')->firstOrFail();
    expect($note->number)->toBe('AV-'.now()->year.'-000001')
        ->and($note->original_sale_id)->toBe($invoice->id)
        ->and($note->total_ttc_cents)->toBe(80000)
        ->and($note->seller_id)->toBe($this->seller->id)
        ->and(($this->level)()->quantity)->toBe(7)
        ->and($invoice->fresh()->total_ttc_cents)->toBe(320000); // the invoice itself never changes

    // Cannot return more than what is left.
    expect(fn () => app(CreateCreditNoteAction::class)->request($invoice, [$line->id => ['quantity' => 4]], RefundMethod::Cash, 'x', owner()))
        ->toThrow(BusinessRuleException::class);
});

it('declares a damaged return, deducts from the customer debt', function () {
    $pro = Customer::factory()->company()->create();
    $invoice = ($this->confirm)(($this->draft)([['product_id' => $this->tyre->id, 'quantity' => 2]], ['customer_id' => $pro->id]), []);
    expect($pro->fresh()->outstandingCents())->toBe(140000);

    app(CreateCreditNoteAction::class)->request($invoice, [$invoice->lines->first()->id => ['quantity' => 1, 'restock' => false]], RefundMethod::Account, 'Pneu abîmé', owner());

    expect($pro->fresh()->outstandingCents())->toBe(70000)
        ->and(($this->level)()->quantity)->toBe(8) // back in and out as damaged
        ->and(StockMovement::query()->where('type', 'damaged')->sum('quantity'))->toEqual(-1);
});

it('exchanges: the credit note becomes a voucher used to pay the new sale', function () {
    $customer = Customer::factory()->create();
    $invoice = ($this->confirm)(($this->draft)([['product_id' => $this->tyre->id, 'quantity' => 1]], ['customer_id' => $customer->id]));
    $voucher = app(CreateCreditNoteAction::class)->request($invoice, [$invoice->lines->first()->id => ['quantity' => 1]], RefundMethod::Voucher, 'Échange', owner());

    $other = Product::factory()->create(['price_retail_cents' => 100000]);
    $this->stock->receive($other, site('M01'), 2, 60000);
    $sale = ($this->draft)([['product_id' => $other->id, 'quantity' => 1]], ['customer_id' => $customer->id]);
    $sale = ($this->confirm)($sale, [
        ['method' => 'credit_note', 'amount_cents' => 80000, 'credit_note_id' => $voucher->id],
        ['method' => 'cash', 'amount_cents' => 20000],
    ]);

    expect($sale->paid_cents)->toBe(100000)->and($voucher->fresh()->voucherBalanceCents())->toBe(0)
        ->and($customer->vouchers())->toHaveCount(0);

    // A spent voucher cannot be used again.
    $again = ($this->draft)([['product_id' => $other->id, 'quantity' => 1]], ['customer_id' => $customer->id]);
    expect(fn () => ($this->confirm)($again, [['method' => 'credit_note', 'amount_cents' => 1000, 'credit_note_id' => $voucher->id]]))
        ->toThrow(BusinessRuleException::class, __('sales.errors.voucher_invalid'));
});

it('deducts trade-ins, creates used tyres awaiting inspection, caps the value (D-52)', function () {
    $sale = ($this->draft)([
        ['product_id' => $this->tyre->id, 'quantity' => 4],
        ['trade_in' => 'tyre', 'value_cents' => 15000, 'meta' => ['size' => '205/55R16', 'tread_mm' => '4,5']],
        ['trade_in' => 'battery', 'value_cents' => 10000],
    ]);
    expect($sale->total_ttc_cents)->toBe(320000 - 25000);
    $sale = ($this->confirm)($sale);

    $tyre = UsedTyre::query()->firstOrFail();
    expect($tyre->status)->toBe(UsedTyreStatus::PendingInspection)
        ->and($tyre->origin->value)->toBe('trade_in')
        ->and($tyre->tread_depth_tenths)->toBe(45)
        ->and($tyre->cost_cents)->toBe(15000)
        ->and($tyre->origin_document_id)->toBe($sale->id);

    $over = ($this->draft)([['product_id' => $this->tyre->id, 'quantity' => 1], ['trade_in' => 'tyre', 'value_cents' => 30000, 'meta' => ['size' => '205 55 16']]]);
    try {
        ($this->confirm)($over);
        $this->fail('approval expected');
    } catch (ApprovalRequiredException $e) {
        expect($e->types)->toBe([ApprovalType::TradeIn]);
    }

    expect(fn () => ($this->draft)([['product_id' => $this->tyre->id, 'quantity' => 1], ['trade_in' => 'battery', 'value_cents' => 90000]]))
        ->toThrow(BusinessRuleException::class, __('sales.errors.trade_in_exceeds'));
});

it('puts a returned new tyre back with its original DOT (audit)', function () {
    $tyre = Product::factory()->tyre(195, 65, '15')->create();
    $this->stock->receive($tyre, site('M01'), 2, 40000, StockMovementType::PurchaseReceipt, null, null, null, new LotInput(12, 2024));
    $invoice = ($this->confirm)(($this->draft)([['product_id' => $tyre->id, 'quantity' => 2]], ['customer_id' => Customer::factory()->create()->id]));

    $owner = owner();
    app(CreateCreditNoteAction::class)->request($invoice, [$invoice->lines->first()->id => ['quantity' => 1]], RefundMethod::Cash, 'Retour', $owner);
    app(CreateCreditNoteAction::class)->request($invoice, [$invoice->lines->first()->id => ['quantity' => 1]], RefundMethod::Cash, 'Retour 2', $owner);

    $lots = TyreLot::query()->where('product_id', $tyre->id)->where('quantity', '>', 0)->get();
    expect($lots->sum('quantity'))->toBe(2)
        ->and($lots->every(fn ($l) => $l->dot_week === 12 && $l->dot_year === 2024))->toBeTrue();
});
