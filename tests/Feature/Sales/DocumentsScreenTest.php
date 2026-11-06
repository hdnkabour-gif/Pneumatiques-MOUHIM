<?php

use App\Enums\QuoteStatus;
use App\Enums\RoleName;
use App\Enums\SaleKind;
use App\Filament\Pages\SaleScreen;
use App\Filament\Resources\Customers\Pages\ViewCustomer;
use App\Filament\Resources\Sales\Pages\ListSales;
use App\Filament\Resources\Sales\Pages\ViewSale;
use App\Filament\Resources\Sales\SaleResource;
use App\Models\ApprovalRequest;
use App\Models\Customer;
use App\Models\Product;
use App\Models\Sale;
use App\Models\UsedTyre;
use App\Services\Approvals\ApprovalService;
use App\Services\Stock\StockService;
use Livewire\Livewire;

beforeEach(function () {
    $this->seller = userWithRole(RoleName::Seller, 'M01');
    $this->seller->update(['default_site_id' => site('M01')->id]);
    $this->tyre = Product::factory()->tyre()->create(['price_retail_cents' => 80000]);
    app(StockService::class)->receive($this->tyre, site('M01'), 10, 50000);
});

it('makes a quote from the sale screen, then converts it in one click', function () {
    $this->actingAs($this->seller);
    $customer = Customer::factory()->create();

    Livewire::test(SaleScreen::class)
        ->call('selectCustomer', $customer->id)
        ->call('addProduct', $this->tyre->id)
        ->call('setQuantity', 0, 4)
        ->call('setKind', 'quote')
        ->assertDontSee(__('sales.screen.payment'))
        ->call('confirm')
        ->assertSee(__('sales.screen.done_quote', ['number' => 'DV-'.now()->year.'-000001']));

    $quote = Sale::query()->where('kind', 'quote')->firstOrFail();
    $this->get(route('sales.pdf', $quote))->assertOk();

    Livewire::test(ListSales::class)->set('activeTab', 'quote')->assertCanSeeTableRecords([$quote]);
    Livewire::test(ViewSale::class, ['record' => $quote->id])->callAction('convert')->assertRedirect();

    $draft = Sale::query()->where('quote_id', $quote->id)->firstOrFail();
    Livewire::test(SaleScreen::class, ['draft' => $draft->id])
        ->assertSet('kind', 'invoice')
        ->assertSet('customerId', $customer->id)
        ->call('confirm');

    expect($quote->fresh()->quote_status)->toBe(QuoteStatus::Converted)
        ->and($draft->fresh()->number)->toStartWith('FA-');
});

it('delivers to a company on a note and makes the recap invoice from the customer page', function () {
    $this->actingAs($this->seller);
    $pro = Customer::factory()->company(10000000)->create();

    $page = Livewire::test(SaleScreen::class)->call('addProduct', $this->tyre->id);
    $page->assertDontSee(__('sales.screen.kind_delivery_note')); // walk-in: no delivery note
    $page->call('selectCustomer', $pro->id)->call('setKind', 'delivery_note')
        ->assertSet('payments', [])
        ->call('confirm')->assertHasNoErrors();

    $note = Sale::query()->where('kind', 'delivery_note')->firstOrFail();
    expect($note->number)->toStartWith('BL-')->and($note->credit_cents)->toBe(80000);

    Livewire::test(ViewCustomer::class, ['record' => $pro->id])
        ->callAction('recap', ['notes' => [$note->id]])
        ->assertRedirect();
    expect($note->fresh()->recap_invoice_id)->not->toBeNull();
});

it('takes a trade-in on the sale screen', function () {
    $this->actingAs($this->seller);

    Livewire::test(SaleScreen::class)
        ->call('addProduct', $this->tyre->id)
        ->call('addTradeIn', 'tyre')
        ->set('cart.1.size', '205 55 16')
        ->set('cart.1.value', '150')
        ->assertSet('payments.0.amount', '650.00')
        ->call('confirm')->assertHasNoErrors();

    expect(UsedTyre::query()->first()->origin->value)->toBe('trade_in')
        ->and(Sale::query()->first()->total_ttc_cents)->toBe(65000);
});

it('returns an item from the invoice page: the seller asks, the owner gets the request', function () {
    $this->actingAs($this->seller);
    $customer = Customer::factory()->create();
    Livewire::test(SaleScreen::class)->call('selectCustomer', $customer->id)->call('addProduct', $this->tyre->id)->call('confirm');
    $invoice = Sale::query()->firstOrFail();
    $line = $invoice->lines->first();

    Livewire::test(ViewSale::class, ['record' => $invoice->id])
        ->callAction('return', ['lines' => [$line->id => ['quantity' => 1, 'restock' => true]], 'refund' => 'voucher', 'reason' => 'Échange taille'])
        ->assertNotified(__('sales.return.sent'));

    $request = ApprovalRequest::query()->where('type', 'credit_note')->firstOrFail();
    $this->actingAs(owner());
    app(ApprovalService::class)->decide($request, auth()->user(), true);

    // The voucher appears on the customer's next sale.
    $this->actingAs($this->seller);
    $voucher = Sale::query()->where('kind', SaleKind::CreditNote->value)->firstOrFail();
    Livewire::test(SaleScreen::class)->call('selectCustomer', $customer->id)->call('addProduct', $this->tyre->id)
        ->assertSee($voucher->number)
        ->call('useVoucher', $voucher->id)
        ->assertSet('payments.1.method', 'credit_note')
        ->assertSet('payments.0.amount', '0.00')
        ->call('confirm')->assertHasNoErrors();

    expect($voucher->fresh()->voucherBalanceCents())->toBe(0);
});

it('shows the document tabs in Arabic', function () {
    $this->seller->update(['locale' => 'ar']);
    $this->actingAs($this->seller)->get(SaleResource::getUrl())
        ->assertOk()->assertSee('عروض الأسعار')->assertSee('الفواتير المضادة');
});
