<?php

use App\Actions\Purchases\PurchaseActions;
use App\Enums\RoleName;
use App\Exceptions\BusinessRuleException;
use App\Filament\Resources\PurchaseOrders\Pages\CreatePurchaseOrder;
use App\Filament\Resources\PurchaseOrders\Pages\ViewPurchaseOrder;
use App\Filament\Resources\PurchaseOrders\PurchaseOrderResource;
use App\Filament\Resources\Suppliers\SupplierResource;
use App\Models\Product;
use App\Models\PurchaseOrder;
use App\Models\StockLevel;
use App\Models\Supplier;
use App\Models\TyreLot;
use Livewire\Livewire;

beforeEach(function () {
    $this->owner = owner();
    $this->storekeeper = userWithRole(RoleName::Storekeeper, 'DEP');
    $this->purchases = app(PurchaseActions::class);
    $this->supplier = Supplier::query()->create(['name' => 'Atlas Pneus SARL', 'opening_balance_cents' => 100000]);
    $this->tyre = Product::factory()->tyre()->create();
});

it('orders, receives in two deliveries with DOT, and the supplier debt follows (§21 achat)', function () {
    $order = $this->purchases->order($this->supplier, site('DEP'), [['product_id' => $this->tyre->id, 'quantity' => 10, 'unit_cost_cents' => 50000]], $this->storekeeper);
    expect($order->number)->toBe('BC-'.now()->year.'-000001')->and($order->status)->toBe('ordered');

    $this->purchases->receive($this->supplier, site('DEP'), [['product_id' => $this->tyre->id, 'quantity' => 6, 'unit_cost_cents' => 50000, 'dot_week' => 10, 'dot_year' => 2026]], $this->storekeeper, 'F-123', $order);
    expect($order->fresh()->status)->toBe('partial');

    $this->purchases->receive($this->supplier, site('DEP'), [['product_id' => $this->tyre->id, 'quantity' => 4, 'unit_cost_cents' => 53000, 'dot_week' => 20, 'dot_year' => 2026]], $this->storekeeper, 'F-130', $order);

    expect($order->fresh()->status)->toBe('received')
        ->and(StockLevel::query()->where(['product_id' => $this->tyre->id, 'site_id' => site('DEP')->id])->value('quantity'))->toBe(10)
        ->and($this->tyre->fresh()->average_cost_cents)->toBe(51200) // (6×500 + 4×530) / 10
        ->and(TyreLot::query()->where('product_id', $this->tyre->id)->pluck('dot_week')->sort()->values()->all())->toBe([10, 20])
        // Opening 1 000 + (3 000 + 2 120) × 1.2
        ->and($this->supplier->balanceCents())->toBe(100000 + 360000 + 254400);

    expect(fn () => $this->purchases->receive($this->supplier, site('DEP'), [['product_id' => $this->tyre->id, 'quantity' => 1, 'unit_cost_cents' => 1]], $this->storekeeper, null, $order->fresh()))
        ->toThrow(BusinessRuleException::class);
});

it('alerts the owner when a cost rises and compares suppliers', function () {
    $other = Supplier::query()->create(['name' => 'Sud Import']);
    $this->purchases->receive($this->supplier, site('DEP'), [['product_id' => $this->tyre->id, 'quantity' => 2, 'unit_cost_cents' => 50000]], $this->storekeeper);
    $this->purchases->receive($other, site('DEP'), [['product_id' => $this->tyre->id, 'quantity' => 2, 'unit_cost_cents' => 56000]], $this->storekeeper);

    expect($this->owner->notifications()->count())->toBe(1)
        ->and(array_column($this->purchases->compare($this->tyre), 'name'))->toBe(['Atlas Pneus SARL', 'Sud Import']);
});

it('pays a supplier (owner only) and keeps sellers away from purchases', function () {
    expect(fn () => $this->purchases->pay($this->supplier, 50000, 'transfer', 'VIR-1', $this->storekeeper))->toThrow(BusinessRuleException::class);
    $this->purchases->pay($this->supplier, 50000, 'transfer', 'VIR-1', $this->owner);
    expect($this->supplier->balanceCents())->toBe(50000);

    $seller = userWithRole(RoleName::Seller, 'M01');
    expect(fn () => $this->purchases->receive($this->supplier, site('M01'), [['product_id' => $this->tyre->id, 'quantity' => 1, 'unit_cost_cents' => 1]], $seller))
        ->toThrow(BusinessRuleException::class);
});

it('orders and receives at once from the screen, then receives the rest of another order', function () {
    $this->actingAs($this->storekeeper);

    Livewire::test(CreatePurchaseOrder::class)
        ->fillForm(['supplier_id' => $this->supplier->id, 'site_id' => site('DEP')->id, 'received_now' => true, 'invoice_ref' => 'F-9',
            'lines' => [['product_id' => $this->tyre->id, 'quantity' => 4, 'unit_cost_cents' => '480', 'dot' => '1226']]])
        ->call('create')->assertHasNoFormErrors();

    $order = PurchaseOrder::query()->firstOrFail();
    expect($order->status)->toBe('received')
        ->and(TyreLot::query()->where('product_id', $this->tyre->id)->value('dot_week'))->toBe(12);

    $o2 = $this->purchases->order($this->supplier, site('DEP'), [['product_id' => $this->tyre->id, 'quantity' => 5, 'unit_cost_cents' => 48000]], $this->storekeeper);
    Livewire::test(ViewPurchaseOrder::class, ['record' => $o2->id])
        ->callAction('receive', ['lines' => [$o2->lines->first()->id => ['quantity' => 2, 'unit_cost_cents' => '480', 'dot' => '']]]);
    expect($o2->fresh()->status)->toBe('partial');

    $this->get(SupplierResource::getUrl())->assertOk()->assertSee('Atlas Pneus SARL');
    $this->actingAs(userWithRole(RoleName::Seller, 'M01'))->get(PurchaseOrderResource::getUrl())->assertForbidden();
});
