<?php

use App\Enums\DocumentType;
use App\Enums\RoleName;
use App\Enums\SaleStatus;
use App\Enums\WorkOrderStatus;
use App\Filament\Pages\DocumentNumbering;
use App\Filament\Pages\SaleScreen;
use App\Filament\Pages\Workshop;
use App\Filament\Resources\Customers\CustomerResource;
use App\Filament\Resources\Customers\Pages\CreateCustomer;
use App\Filament\Resources\Customers\Pages\ListCustomers;
use App\Filament\Resources\Sales\Pages\ListSales;
use App\Filament\Resources\Sales\SaleResource;
use App\Models\ApprovalRequest;
use App\Models\Customer;
use App\Models\Product;
use App\Models\ReplenishmentRequest;
use App\Models\Sale;
use App\Models\WorkOrder;
use App\Services\Sales\SaleDocuments;
use App\Services\SequenceService;
use App\Services\Stock\StockService;
use App\Support\AmountInWords;
use App\Support\Money;
use Illuminate\Support\Facades\Hash;
use Livewire\Livewire;
use Smalot\PdfParser\Parser;

beforeEach(function () {
    $this->seller = userWithRole(RoleName::Seller, 'M01');
    $this->seller->update(['default_site_id' => site('M01')->id]);
    $this->tyre = Product::factory()->tyre()->create(['price_retail_cents' => 80000]);
    app(StockService::class)->receive($this->tyre, site('M01'), 10, 50000);
});

it('sells in one screen: quick customer, product search by size, cash by default (§10.1)', function () {
    $this->actingAs($this->seller);

    $page = Livewire::test(SaleScreen::class)
        ->assertSet('siteId', site('M01')->id)
        ->set('customerSearch', '0661 23 45 67')
        ->call('openNewCustomer')
        ->assertSet('newCustomer.phone', '0661 23 45 67')
        ->set('newCustomer.name', 'Karim Alaoui')
        ->set('newCustomer.plate', '12345-أ-6')
        ->call('saveNewCustomer')
        ->assertHasNoErrors()
        ->set('search', '2055516')
        ->assertSee($this->tyre->name)
        ->call('addProduct', $this->tyre->id)
        ->call('setQuantity', 0, 4)
        ->assertSee(Money::format(320000))
        ->assertSet('payments.0.method', 'cash')
        ->assertSet('payments.0.amount', '3200.00')
        ->call('confirm')
        ->assertHasNoErrors();

    $sale = Sale::query()->firstOrFail();
    $customer = Customer::query()->firstOrFail();
    expect($sale->status)->toBe(SaleStatus::Confirmed)
        ->and($sale->customer_id)->toBe($customer->id)
        ->and($sale->customer_vehicle_id)->toBe($customer->vehicles()->first()->id)
        ->and($customer->phone_normalized)->toBe('212661234567')
        ->and($sale->paid_cents)->toBe(320000);
    $page->assertSet('doneSaleId', $sale->id)->assertSee($sale->number);
});

it('finds an existing customer by plate typed differently', function () {
    $this->actingAs($this->seller);
    $c = Customer::factory()->create(['name' => 'Société Atlas']);
    $c->vehicles()->create(['plate' => '12345-أ-6']);

    Livewire::test(SaleScreen::class)->set('customerSearch', '12345 أ 6')->assertSee('Société Atlas');
});

it('splits the payment and leaves the rest on credit with the owner PIN on the spot', function () {
    $owner = owner();
    $owner->forceFill(['approval_pin' => Hash::make('2468')])->save();
    $this->actingAs($this->seller);
    $customer = Customer::factory()->create();

    Livewire::test(SaleScreen::class)
        ->call('selectCustomer', $customer->id)
        ->call('addProduct', $this->tyre->id)
        ->call('addPayment', 'card')
        ->assertSet('payments.0.method', 'card')
        ->set('payments.0.amount', '500')
        ->call('confirm')
        ->assertSet('neededApprovals', ['credit_limit'])
        ->set('pin', '1111')
        ->call('approveWithPin')
        ->assertHasErrors('pin')
        ->set('pin', '2468')
        ->call('approveWithPin')
        ->assertHasNoErrors();

    $sale = Sale::query()->firstOrFail();
    expect($sale->status)->toBe(SaleStatus::Confirmed)
        ->and($sale->paid_cents)->toBe(50000)
        ->and($sale->credit_cents)->toBe(30000);
});

it('keeps the draft and sends the request to the owner when he is away', function () {
    $this->actingAs($this->seller);

    Livewire::test(SaleScreen::class)
        ->call('addProduct', $this->tyre->id)
        ->set('cart.0.discount', '15')
        ->call('confirm')
        ->assertSet('neededApprovals', ['discount'])
        ->set('approvalReason', 'Client fidèle')
        ->call('sendApproval')
        ->assertSet('approvalSent', true);

    expect(Sale::query()->first()->status)->toBe(SaleStatus::Draft)
        ->and(ApprovalRequest::query()->where('type', 'discount')->where('status', 'pending')->exists())->toBeTrue();

    // The draft can be resumed from the list.
    Livewire::test(SaleScreen::class, ['draft' => Sale::query()->first()->id])->assertSet('cart.0.discount', '15.00');
});

it('asks the depot for a product missing in the shop', function () {
    $this->actingAs($this->seller);
    $other = Product::factory()->tyre(195, 65, '15')->create();
    app(StockService::class)->receive($other, site('DEP'), 8, 40000);

    Livewire::test(SaleScreen::class)
        ->set('search', '195/65R15')
        ->assertSee(__('sales.screen.ask_depot'))
        ->call('requestFromDepot', $other->id);

    $request = ReplenishmentRequest::query()->with('lines')->firstOrFail();
    expect($request->site_id)->toBe(site('M01')->id)->and($request->source_site_id)->toBe(site('DEP')->id);
});

it('prints the A4 invoice with the amount in words, for allowed users only', function () {
    $this->actingAs($this->seller);
    Livewire::test(SaleScreen::class)->call('addProduct', $this->tyre->id)->call('confirm');
    $sale = Sale::query()->firstOrFail();

    $this->get(route('sales.pdf', $sale))->assertOk()->assertHeader('Content-Type', 'application/pdf');

    $this->actingAs(userWithRole(RoleName::Seller, 'M02'))->get(route('sales.pdf', $sale))->assertForbidden();
    $this->actingAs(userWithRole(RoleName::Technician, 'M01'))->get(route('sales.pdf', $sale))->assertForbidden();

    // Signed link for the customer; tampered or unsigned links are refused.
    auth()->logout();
    $url = app(SaleDocuments::class)->publicUrl($sale);
    $this->get($url)->assertOk()->assertHeader('Content-Type', 'application/pdf');
    $this->get(route('sales.public-pdf', $sale))->assertForbidden();

    expect(AmountInWords::french(128150))->toBe('Mille deux cent quatre-vingt-un dirhams et cinquante centimes')
        ->and(AmountInWords::french(100))->toBe('Un dirham');
});

it('builds the WhatsApp link with the signed invoice', function () {
    $this->actingAs($this->seller);
    $customer = Customer::factory()->create(['phone' => '0661234567', 'name' => 'Karim']);
    Livewire::test(SaleScreen::class)->call('selectCustomer', $customer->id)->call('addProduct', $this->tyre->id)->call('confirm');

    $url = app(SaleDocuments::class)->whatsappUrl(Sale::query()->firstOrFail());
    expect($url)->toStartWith('https://wa.me/212661234567?text=')
        ->and(rawurldecode($url))->toContain('Karim')->toContain('FA-')->toContain('/d/invoice/');
});

it('keeps sale pages away from technicians and storekeepers, even by URL (§21)', function () {
    foreach ([RoleName::Technician, RoleName::Storekeeper] as $role) {
        $this->actingAs(userWithRole($role, 'M01'));
        $this->get(SaleScreen::getUrl())->assertForbidden();
        $this->get(SaleResource::getUrl())->assertForbidden();
        $this->get(CustomerResource::getUrl())->assertForbidden();
        $this->get(Workshop::getUrl())->assertForbidden();
    }
});

it('hides other sites invoices from a seller', function () {
    $this->actingAs($this->seller);
    Livewire::test(SaleScreen::class)->call('addProduct', $this->tyre->id)->call('confirm');
    $sale = Sale::query()->firstOrFail();

    $this->actingAs(userWithRole(RoleName::Seller, 'M02'));
    Livewire::test(ListSales::class)->assertCanNotSeeTableRecords([$sale]);
    $this->get(SaleResource::getUrl('view', ['record' => $sale]))->assertNotFound();
});

it('lets the owner continue the paper numbering from the settings page (D-49)', function () {
    $this->actingAs(owner());
    $year = now()->year;

    Livewire::test(DocumentNumbering::class)
        ->set('data.invoice.prefix', 'fa')
        ->set('data.invoice.next', 1251)
        ->call('save')
        ->assertHasNoErrors();

    expect(app(SequenceService::class)->lastNumber(DocumentType::Invoice, $year))->toBe(1250)
        ->and(app(SequenceService::class)->nextDocumentNumber(DocumentType::Invoice))->toBe("FA-{$year}-001251");

    $this->actingAs($this->seller)->get(DocumentNumbering::getUrl())->assertForbidden();
});

it('moves a work order through the workshop with big buttons (D-53)', function () {
    $tech = userWithRole(RoleName::Technician, 'M01');
    $service = Product::factory()->service()->create(['name' => 'Équilibrage']);
    $this->actingAs($this->seller);
    $customer = Customer::factory()->create();
    $vehicle = $customer->vehicles()->create(['plate' => '1-A-1']);

    Livewire::test(SaleScreen::class)->call('selectCustomer', $customer->id)
        ->call('addProduct', $service->id)
        ->call('toggleTechnician', $tech->id)
        ->call('confirm');

    $order = WorkOrder::query()->firstOrFail();
    expect($order->technicians->pluck('id')->all())->toBe([$tech->id]);

    Livewire::test(Workshop::class)
        ->assertSee('1-A-1')
        ->call('move', $order->id, 'done')   // not allowed from "pending"
        ->call('move', $order->id, 'in_progress')
        ->call('move', $order->id, 'done')
        ->set("edit.{$order->id}.next_oil_km", '95000')
        ->call('saveNote', $order->id);

    $order->refresh();
    expect($order->status)->toBe(WorkOrderStatus::Done)
        ->and($order->started_at)->not->toBeNull()
        ->and($order->finished_at)->not->toBeNull()
        ->and($vehicle->fresh()->next_oil_change_km)->toBe(95000);
});

it('creates customers from the list, owner fields hidden from sellers', function () {
    $this->actingAs($this->seller);

    Livewire::test(CreateCustomer::class)
        ->fillForm(['name' => 'Transport Sahara', 'phone' => '0522 11 22 33', 'type' => 'company', 'ice' => '001234567000089'])
        ->assertFormFieldIsHidden('credit_limit_cents')
        ->call('create')
        ->assertHasNoFormErrors();

    expect(Customer::query()->first()->credit_limit_cents)->toBeNull()
        ->and(Customer::query()->first()->created_by)->toBe($this->seller->id);

    Livewire::test(ListCustomers::class)->searchTable('0522112233')->assertCanSeeTableRecords(Customer::all());
});

it('renders the sale screen and the workshop in Arabic', function () {
    $this->seller->update(['locale' => 'ar']);
    $this->actingAs($this->seller);

    $this->get(SaleScreen::getUrl())->assertOk()->assertSee('بيع جديد')->assertSee('dir="rtl"', false);
    $this->get(Workshop::getUrl())->assertOk()->assertSee('الورشة');
});

it('prints the company letterhead (name, legal details, brands) on documents', function () {
    $this->actingAs($this->seller);
    Livewire::test(SaleScreen::class)->call('addProduct', $this->tyre->id)->call('confirm');
    $pdf = app(SaleDocuments::class)->pdf(Sale::query()->firstOrFail());
    $text = (new Parser)->parseContent($pdf)->getText();

    expect($text)->toContain('PNEUMATIQUE MOUHIM')->toContain('000167198000034')->toContain('MICHELIN');
})->skip(! class_exists(Parser::class), 'PDF parser not installed');
