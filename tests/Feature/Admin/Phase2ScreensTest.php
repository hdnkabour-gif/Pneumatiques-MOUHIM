<?php

use App\Actions\Stock\RequestStockAdjustmentAction;
use App\Actions\Transfers\SendTransferAction;
use App\Actions\UsedTyres\UsedTyreActions;
use App\Enums\ApprovalStatus;
use App\Enums\Permission;
use App\Enums\RoleName;
use App\Enums\TransferStatus;
use App\Enums\UsedTyreCheck;
use App\Enums\UsedTyreOrigin;
use App\Enums\UsedTyreStatus;
use App\Filament\Resources\ApprovalRequests\Pages\ViewApprovalRequest;
use App\Filament\Resources\Bundles\Pages\CreateBundle;
use App\Filament\Resources\Products\Pages\CreateProduct;
use App\Filament\Resources\Products\Pages\EditProduct;
use App\Filament\Resources\Products\Pages\ListProducts;
use App\Filament\Resources\Transfers\Pages\CreateTransfer;
use App\Filament\Resources\Transfers\Pages\ViewTransfer;
use App\Filament\Resources\UsedTyres\Pages\ViewUsedTyre;
use App\Models\Category;
use App\Models\Product;
use App\Models\Transfer;
use App\Models\UsedTyre;
use App\Services\Stock\StockService;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Support\Facades\Hash;
use Livewire\Livewire;
use Spatie\Permission\Models\Permission as PermissionModel;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;

it('creates a new tyre with its size and an automatic name and SKU', function () {
    $this->actingAs(owner());
    $tourisme = Category::query()->where('name', 'Tourisme')->firstOrFail();

    Livewire::test(CreateProduct::class)
        ->fillForm([
            'category_id' => $tourisme->id,
            'price_retail_cents' => '850,00',
            'tyreSpec' => ['width' => 205, 'aspect_ratio' => 55, 'rim_diameter' => 16, 'load_index' => '91', 'speed_index' => 'v'],
        ])
        ->call('create')
        ->assertHasNoFormErrors();

    $product = Product::query()->with('tyreSpec')->latest('id')->firstOrFail();
    expect($product->sku)->toBe('PN-000001')
        ->and($product->name)->toBe('205/55 R16 91V')
        ->and($product->price_retail_cents)->toBe(85000)
        ->and($product->tyreSpec->width)->toBe(205);
});

it('finds a tyre whatever the way the size is typed (§8)', function (string $search) {
    $this->actingAs(owner());
    $tyre = Product::factory()->tyre(205, 55, '16')->create();
    $other = Product::factory()->tyre(195, 65, '15')->create();

    Livewire::test(ListProducts::class)
        ->searchTable($search)
        ->assertCanSeeTableRecords([$tyre])
        ->assertCanNotSeeTableRecords([$other]);
})->with(['205 55 16', '2055516', '205/55R16', '205/55 R16 91V']);

it('never shows purchase costs to a seller (§21 Permissions 1)', function () {
    $product = Product::factory()->create(['reference_cost_cents' => 12345]);
    app(StockService::class)->receive($product, site('M01'), 2, 54321);
    $seller = userWithRole(RoleName::Seller, 'M01');
    $seller->givePermissionTo(Permission::ProductsManage->value); // even with catalogue editing rights

    $this->actingAs($seller);
    $html = $this->get("/admin/products/{$product->id}/edit")->assertOk()->getContent();
    $list = $this->get('/admin/products')->assertOk()->getContent();
    $stock = $this->get('/admin/stock-levels')->assertOk()->getContent();

    foreach ([$html, $list, $stock] as $page) {
        expect($page)->not->toContain('123,45')->not->toContain('543,21')
            ->not->toContain(__('catalog.fields.average_cost'))->not->toContain(__('stock.fields.value'));
    }

    Livewire::test(EditProduct::class, ['record' => $product->getRouteKey()])
        ->assertFormFieldIsHidden('reference_cost_cents');
});

it('rejects a bundle whose lines do not add up to its price', function () {
    $this->actingAs(owner());
    $item = Product::factory()->create();

    Livewire::test(CreateBundle::class)
        ->fillForm(['name' => 'Vidange complète', 'price_retail_cents' => '500,00', 'bundleItems' => [
            ['product_id' => $item->id, 'quantity' => 2, 'unit_price_cents' => '200,00'],
        ]])
        ->call('create')
        ->assertHasFormErrors(['price_retail_cents']);
});

it('sends and receives a transfer from the screens', function () {
    $storekeeper = userWithRole(RoleName::Storekeeper, 'DEP');
    $product = Product::factory()->create();
    app(StockService::class)->receive($product, site('DEP'), 10, 1000);

    $this->actingAs($storekeeper);
    Livewire::test(CreateTransfer::class)
        ->fillForm(['from_site_id' => site('DEP')->id, 'to_site_id' => site('M01')->id, 'lines' => [['product_id' => $product->id, 'quantity' => 4]]])
        ->call('create')
        ->assertHasNoFormErrors();

    $transfer = Transfer::query()->latest('id')->firstOrFail();
    expect($transfer->status)->toBe(TransferStatus::InTransit);

    $this->actingAs(userWithRole(RoleName::SiteManager, 'M01'));
    $line = $transfer->lines()->first();
    Livewire::test(ViewTransfer::class, ['record' => $transfer->getRouteKey()])
        ->callAction('receive', ['received' => [$line->id => 4], 'reasons' => []])
        ->assertHasNoActionErrors();

    expect($transfer->fresh()->status)->toBe(TransferStatus::Received)
        ->and(app(StockService::class)->quantityAt($product, site('M01')))->toBe(4);
});

it('refuses a transfer larger than the stock with a clear message', function () {
    $product = Product::factory()->create();
    app(StockService::class)->receive($product, site('DEP'), 2, 1000);
    $this->actingAs(userWithRole(RoleName::Storekeeper, 'DEP'));

    Livewire::test(CreateTransfer::class)
        ->fillForm(['from_site_id' => site('DEP')->id, 'to_site_id' => site('M01')->id, 'lines' => [['product_id' => $product->id, 'quantity' => 5]]])
        ->call('create')
        ->assertHasErrors(['data.lines']);

    expect(Transfer::query()->count())->toBe(0);
});

it('lets the owner approve from the approval screen', function () {
    $owner = owner();
    $product = Product::factory()->create();
    app(StockService::class)->receive($product, site('DEP'), 5, 1000);
    $request = app(RequestStockAdjustmentAction::class)->execute($product, site('DEP'), -2, 'Casse', userWithRole(RoleName::Storekeeper, 'DEP'), true);

    $this->actingAs($owner);
    Livewire::test(ViewApprovalRequest::class, ['record' => $request->getRouteKey()])
        ->callAction('approve', ['comment' => 'OK'])
        ->assertHasNoActionErrors();

    expect($request->fresh()->status)->toBe(ApprovalStatus::Approved)
        ->and(app(StockService::class)->quantityAt($product, site('DEP')))->toBe(3);
});

it('lets the owner approve on the employee screen with his PIN', function () {
    $owner = owner();
    $owner->forceFill(['approval_pin' => Hash::make('1357')])->save();
    $storekeeper = userWithRole(RoleName::Storekeeper, 'DEP');
    $product = Product::factory()->create();
    app(StockService::class)->receive($product, site('DEP'), 5, 1000);
    $request = app(RequestStockAdjustmentAction::class)->execute($product, site('DEP'), 1, 'Recomptage', $storekeeper);

    $this->actingAs($storekeeper);
    Livewire::test(ViewApprovalRequest::class, ['record' => $request->getRouteKey()])
        ->assertActionHidden('approve')
        ->callAction('approveWithPin', ['pin' => '1357']);

    expect($request->fresh()->status)->toBe(ApprovalStatus::Approved)
        ->and($request->fresh()->decided_via)->toBe('pin');
});

it('inspects a used tyre from its screen', function () {
    $seller = userWithRole(RoleName::Seller, 'M01');
    $tyre = app(UsedTyreActions::class)->register(UsedTyre::factory()->make()->getAttributes(), site('M01'), UsedTyreOrigin::TradeIn, $seller);
    $checks = collect(UsedTyreCheck::cases())->mapWithKeys(fn ($c) => [$c->value => '1'])->all();
    $checks[UsedTyreCheck::Bulges->value] = '0';

    $this->actingAs($seller);
    Livewire::test(ViewUsedTyre::class, ['record' => $tyre->getRouteKey()])
        ->callAction('inspect', ['checks' => $checks, 'tread_depth' => '4.5']);

    expect($tyre->fresh()->status)->toBe(UsedTyreStatus::Unsellable)
        ->and($tyre->fresh()->tread_depth_tenths)->toBe(45);
});

it('denies phase 2 screens by role, even by direct URL', function (RoleName $role, string $url, int $status) {
    $this->actingAs(userWithRole($role, 'M01'))->get($url)->assertStatus($status);
})->with([
    'technician – products' => [RoleName::Technician, '/admin/products', 403],
    'technician – stock' => [RoleName::Technician, '/admin/stock-levels', 403],
    'technician – used tyres' => [RoleName::Technician, '/admin/used-tyres', 403],
    'seller – new product' => [RoleName::Seller, '/admin/products/create', 403],
    'seller – new transfer' => [RoleName::Seller, '/admin/transfers/create', 403],
    'seller – inventory' => [RoleName::Seller, '/admin/inventory-counts', 403],
    'seller – approvals' => [RoleName::Seller, '/admin/approval-requests', 403],
    'site manager – new transfer' => [RoleName::SiteManager, '/admin/transfers/create', 403],
    'storekeeper – categories edit' => [RoleName::Storekeeper, '/admin/products/create', 403],
    'seller – stock (allowed)' => [RoleName::Seller, '/admin/stock-levels', 200],
]);

it('hides another site\'s used tyres and transfers from a M01 user', function () {
    $owner = owner();
    $product = Product::factory()->create();
    app(StockService::class)->receive($product, site('DEP'), 5, 1000);
    $toM02 = app(SendTransferAction::class)->execute(site('DEP'), site('M02'), [$product->id => 1], $owner);
    $tyreM02 = app(UsedTyreActions::class)->register(UsedTyre::factory()->make()->getAttributes(), site('M02'), UsedTyreOrigin::Supplier, $owner);

    $this->actingAs(userWithRole(RoleName::SiteManager, 'M01'));
    $this->get("/admin/transfers/{$toM02->id}")->assertNotFound();
    $this->get("/admin/used-tyres/{$tyreM02->id}")->assertNotFound();
});

it('grants a new phase permission to existing roles on deploy, without restoring removed ones', function () {
    $seller = Role::findByName(RoleName::Seller->value);
    $seller->revokePermissionTo(Permission::StockView->value);
    PermissionModel::findByName(Permission::ProductsView->value)->delete(); // simulate a permission introduced by a new phase
    app(PermissionRegistrar::class)->forgetCachedPermissions();

    $this->seed(RolesAndPermissionsSeeder::class);
    $seller = $seller->fresh();

    expect($seller->hasPermissionTo(Permission::ProductsView->value))->toBeTrue()
        ->and($seller->hasPermissionTo(Permission::StockView->value))->toBeFalse();
});
