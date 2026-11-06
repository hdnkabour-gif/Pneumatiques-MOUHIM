<?php

use App\Actions\Sales\ConfirmSaleAction;
use App\Actions\Sales\RequestSaleApprovalAction;
use App\Actions\Sales\SaveSaleDraftAction;
use App\Actions\UsedTyres\UsedTyreActions;
use App\Enums\ApprovalStatus;
use App\Enums\ApprovalType;
use App\Enums\DocumentType;
use App\Enums\RoleName;
use App\Enums\SaleStatus;
use App\Enums\StockMovementType;
use App\Enums\UsedTyreCheck;
use App\Enums\UsedTyreOrigin;
use App\Enums\UsedTyreStatus;
use App\Enums\WorkOrderStatus;
use App\Exceptions\ApprovalRequiredException;
use App\Exceptions\BusinessRuleException;
use App\Exceptions\InsufficientStockException;
use App\Models\ApprovalRequest;
use App\Models\Customer;
use App\Models\Product;
use App\Models\Sale;
use App\Models\StockLevel;
use App\Models\StockMovement;
use App\Models\UsedTyre;
use App\Services\Approvals\ApprovalService;
use App\Services\SequenceService;
use App\Services\SettingsService;
use App\Services\Stock\LotInput;
use App\Services\Stock\StockService;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;

beforeEach(function () {
    $this->seller = userWithRole(RoleName::Seller, 'M01');
    $this->stock = app(StockService::class);
    $this->tyre = Product::factory()->tyre()->create(['price_retail_cents' => 80000, 'price_pro_cents' => 70000]);
    $this->stock->receive($this->tyre, site('M01'), 20, 50000, StockMovementType::PurchaseReceipt, null, null, null, new LotInput(10, 2026));
    $this->draft = fn (array $lines, array $extra = [], $user = null) => app(SaveSaleDraftAction::class)
        ->execute(['site_id' => site('M01')->id, 'lines' => $lines, ...$extra], $user ?? $this->seller);
    $this->confirm = fn (Sale $sale, ?array $payments = null, $user = null, array $techs = []) => app(ConfirmSaleAction::class)
        ->execute($sale, $payments ?? [['method' => 'cash', 'amount_cents' => $sale->total_ttc_cents]], $user ?? $this->seller, $techs);
});

it('sells 4 tyres at M01 with cash: invoice number, VAT from TTC, stock out at M01 only (§21)', function () {
    $sale = ($this->draft)([['product_id' => $this->tyre->id, 'quantity' => 4]]);

    expect($sale->status)->toBe(SaleStatus::Draft)->and($sale->number)->toBeNull()
        ->and($sale->total_ttc_cents)->toBe(320000)
        ->and($sale->total_ht_cents)->toBe(266667)
        ->and($sale->total_vat_cents)->toBe(53333)
        ->and($sale->lines->first()->vat_rate_bp)->toBe(2000);

    $sale = ($this->confirm)($sale);

    expect($sale->status)->toBe(SaleStatus::Confirmed)
        ->and($sale->number)->toBe('FA-'.now()->year.'-000001')
        ->and($sale->paid_cents)->toBe(320000)->and($sale->credit_cents)->toBe(0)
        ->and($sale->payments)->toHaveCount(1)
        ->and($sale->lines->first()->unit_cost_cents)->toBe(50000)
        ->and($sale->marginCents())->toBe(266667 - 200000)
        ->and(StockLevel::query()->where(['product_id' => $this->tyre->id, 'site_id' => site('M01')->id])->value('quantity'))->toBe(16)
        ->and(StockMovement::query()->where('document_type', $sale->getMorphClass())->where('document_id', $sale->id)->sum('quantity'))->toEqual(-4)
        ->and($sale->workOrder)->toBeNull();
});

it('refuses to sell more than the site holds (§21)', function () {
    $sale = ($this->draft)([['product_id' => $this->tyre->id, 'quantity' => 30]]);

    expect(fn () => ($this->confirm)($sale))->toThrow(InsufficientStockException::class);
    expect($sale->fresh()->status)->toBe(SaleStatus::Draft)
        ->and(Sale::query()->whereNotNull('number')->count())->toBe(0);
});

it('uses the customer price level and applies a discount within the seller cap', function () {
    $pro = Customer::factory()->company()->create();
    $sale = ($this->draft)([['product_id' => $this->tyre->id, 'quantity' => 1, 'discount_bp' => 500]], ['customer_id' => $pro->id]);

    expect($sale->lines->first()->list_price_ttc_cents)->toBe(70000)
        ->and($sale->total_ttc_cents)->toBe(66500)
        ->and($sale->discount_cents)->toBe(3500);

    expect(($this->confirm)($sale)->status)->toBe(SaleStatus::Confirmed);
});

it('asks the owner for a discount above the seller cap, then confirms once granted', function () {
    $sale = ($this->draft)([['product_id' => $this->tyre->id, 'quantity' => 1, 'discount_bp' => 1000]]);

    try {
        ($this->confirm)($sale);
        $this->fail('approval expected');
    } catch (ApprovalRequiredException $e) {
        expect($e->types)->toBe([ApprovalType::Discount]);
    }

    [$request] = app(RequestSaleApprovalAction::class)->execute($sale, [ApprovalType::Discount], 'Client fidèle', $this->seller);
    expect($request->status)->toBe(ApprovalStatus::Pending);

    app(ApprovalService::class)->decide($request, owner(), true);

    expect(($this->confirm)($sale->fresh())->status)->toBe(SaleStatus::Confirmed);
});

it('invalidates an approval when the cart changes afterwards', function () {
    $sale = ($this->draft)([['product_id' => $this->tyre->id, 'quantity' => 1, 'discount_bp' => 1500]]);
    [$request] = app(RequestSaleApprovalAction::class)->execute($sale, [ApprovalType::Discount], 'Remise', $this->seller);
    app(ApprovalService::class)->decide($request, owner(), true);

    $sale = app(SaveSaleDraftAction::class)->execute(
        ['site_id' => site('M01')->id, 'lines' => [['product_id' => $this->tyre->id, 'quantity' => 2, 'discount_bp' => 1500]]],
        $this->seller, $sale,
    );

    expect(fn () => ($this->confirm)($sale))->toThrow(ApprovalRequiredException::class);
});

it('lets the site manager give up to 10 % and the owner any discount', function () {
    $manager = userWithRole(RoleName::SiteManager, 'M01');
    $sale = ($this->draft)([['product_id' => $this->tyre->id, 'quantity' => 1, 'discount_bp' => 1000]], [], $manager);
    expect(($this->confirm)($sale, null, $manager)->status)->toBe(SaleStatus::Confirmed);

    $owner = owner();
    $sale = ($this->draft)([['product_id' => $this->tyre->id, 'quantity' => 1, 'discount_bp' => 3000]], [], $owner);
    expect(($this->confirm)($sale, null, $owner)->status)->toBe(SaleStatus::Confirmed)
        // The owner's own decisions are still journalled as self-approved requests.
        ->and(ApprovalRequest::query()->where('type', ApprovalType::BelowCost->value)->value('decided_via'))->toBe('self');
});

it('blocks a price below cost without approval', function () {
    $sale = ($this->draft)([['product_id' => $this->tyre->id, 'quantity' => 1, 'unit_price_ttc_cents' => 59000]], [], userWithRole(RoleName::SiteManager, 'M01'));

    // 59 000 TTC = 49 167 HT < 50 000 cost; the 26 % discount is also above the cap.
    try {
        ($this->confirm)($sale, null, $sale->seller);
        $this->fail('approval expected');
    } catch (ApprovalRequiredException $e) {
        expect($e->types)->toContain(ApprovalType::BelowCost)->toContain(ApprovalType::Discount);
    }
});

it('takes partial payment by cash, card and cheque and leaves the rest on credit (D-50)', function () {
    $pro = Customer::factory()->company(1000000)->create();
    $sale = ($this->draft)([['product_id' => $this->tyre->id, 'quantity' => 4]], ['customer_id' => $pro->id]); // 4 × 700 = 2 800 MAD

    $sale = ($this->confirm)($sale, [
        ['method' => 'cash', 'amount_cents' => 50000],
        ['method' => 'card', 'amount_cents' => 30000],
        ['method' => 'cheque', 'amount_cents' => 100000, 'cheque_number' => '1234567', 'bank' => 'CIH', 'drawer' => 'Transport X', 'due_date' => now()->addDays(30)->toDateString()],
    ]);

    expect($sale->paid_cents)->toBe(180000)
        ->and($sale->credit_cents)->toBe(100000)
        ->and($sale->payments)->toHaveCount(3)
        ->and($pro->fresh()->outstandingCents())->toBe(100000);
});

it('refuses credit without a customer and cheques without details', function () {
    $sale = ($this->draft)([['product_id' => $this->tyre->id, 'quantity' => 1]]);

    expect(fn () => ($this->confirm)($sale, [['method' => 'cash', 'amount_cents' => 1000]]))
        ->toThrow(BusinessRuleException::class, __('sales.errors.credit_needs_customer'));
    expect(fn () => ($this->confirm)($sale, [['method' => 'cheque', 'amount_cents' => 80000]]))
        ->toThrow(BusinessRuleException::class, __('sales.errors.cheque_details'));
    expect(fn () => ($this->confirm)($sale, [['method' => 'cash', 'amount_cents' => 90000]]))
        ->toThrow(BusinessRuleException::class, __('sales.errors.overpaid'));
});

it('blocks credit above the customer limit (§21 credit scenario)', function () {
    $pro = Customer::factory()->company(1000000)->create(); // 10 000 MAD
    $big = Product::factory()->create(['price_pro_cents' => 300000, 'price_retail_cents' => 300000]);
    $this->stock->receive($big, site('M01'), 10, 100000);

    // Opening balance of 3 000 MAD is phase 4; an earlier credit sale stands in for it.
    $first = ($this->draft)([['product_id' => $big->id, 'quantity' => 3]], ['customer_id' => $pro->id]); // 9 000
    ($this->confirm)($first, []);
    expect($pro->fresh()->outstandingCents())->toBe(900000);

    $second = ($this->draft)([['product_id' => $big->id, 'quantity' => 1]], ['customer_id' => $pro->id]);
    expect(fn () => ($this->confirm)($second, []))->toThrow(ApprovalRequiredException::class);

    [$request] = app(RequestSaleApprovalAction::class)->execute($second, [ApprovalType::CreditLimit], 'Client sûr', $this->seller, 300000);
    app(ApprovalService::class)->decide($request, owner(), true);
    expect(($this->confirm)($second->fresh(), [])->credit_cents)->toBe(300000);
});

it('needs approval for any credit to a customer without a limit (D-54)', function () {
    $customer = Customer::factory()->create();
    $sale = ($this->draft)([['product_id' => $this->tyre->id, 'quantity' => 1]], ['customer_id' => $customer->id]);

    expect(fn () => ($this->confirm)($sale, [['method' => 'cash', 'amount_cents' => 40000]]))->toThrow(ApprovalRequiredException::class);
});

it('creates a work order for services, takes their consumables and assigns technicians (D-53)', function () {
    $valve = Product::factory()->create(['name' => 'Valve']);
    $this->stock->receive($valve, site('M01'), 50, 300);
    $fitting = Product::factory()->service()->create(['name' => 'Montage']);
    $fitting->consumables()->create(['product_id' => $valve->id, 'quantity' => 1]);
    $tech = userWithRole(RoleName::Technician, 'M01');

    $sale = ($this->draft)([['product_id' => $this->tyre->id, 'quantity' => 2], ['product_id' => $fitting->id, 'quantity' => 2]]);
    $sale = ($this->confirm)($sale, null, null, [$tech->id]);

    expect($sale->workOrder)->not->toBeNull()
        ->and($sale->workOrder->number)->toBe('OT-'.now()->year.'-000001')
        ->and($sale->workOrder->status)->toBe(WorkOrderStatus::Pending)
        ->and($sale->workOrder->technicians->pluck('id')->all())->toBe([$tech->id])
        ->and(StockLevel::query()->where(['product_id' => $valve->id, 'site_id' => site('M01')->id])->value('quantity'))->toBe(48)
        ->and($sale->lines->firstWhere('kind', 'service')->unit_cost_cents)->toBe(300);
});

it('prices a service by rim size and expands a bundle into its parts', function () {
    $balancing = Product::factory()->service()->create(['price_retail_cents' => 4000]);
    $balancing->priceTiers()->create(['min_rim_diameter' => 13, 'max_rim_diameter' => 15, 'price_cents' => 3000]);
    $balancing->priceTiers()->create(['min_rim_diameter' => 16, 'max_rim_diameter' => 18, 'price_cents' => 5000]);
    $bundle = Product::factory()->bundle()->create(['price_retail_cents' => 10000]);
    $oil = Product::factory()->create(['name' => 'Huile 5W30']);
    $this->stock->receive($oil, site('M01'), 5, 4000);
    $bundle->bundleItems()->create(['product_id' => $oil->id, 'quantity' => 1, 'unit_price_cents' => 8000]);
    $bundle->bundleItems()->create(['product_id' => $balancing->id, 'quantity' => 1, 'unit_price_cents' => 2000]);

    $sale = ($this->draft)([
        ['product_id' => $this->tyre->id, 'quantity' => 1],
        ['product_id' => $balancing->id, 'quantity' => 1],
        ['product_id' => $bundle->id, 'quantity' => 1],
    ]);

    expect($sale->lines->pluck('list_price_ttc_cents')->all())->toBe([80000, 5000, 8000, 2000])
        ->and($sale->lines->pluck('bundle_id')->filter()->count())->toBe(2)
        ->and($sale->total_ttc_cents)->toBe(95000);
});

it('sells an inspected used tyre and never one that failed the check', function () {
    $actions = app(UsedTyreActions::class);
    $checks = collect(UsedTyreCheck::cases())->mapWithKeys(fn ($c) => [$c->value => true])->all();
    $good = $actions->register(UsedTyre::factory()->make()->getAttributes(), site('M01'), UsedTyreOrigin::Supplier, $this->seller);
    $actions->inspect($good, $checks, $this->seller, 50);
    $bad = $actions->register(UsedTyre::factory()->make()->getAttributes(), site('M01'), UsedTyreOrigin::Supplier, $this->seller);
    $actions->inspect($bad, [...$checks, UsedTyreCheck::Bulges->value => false], $this->seller, 50);

    expect(fn () => ($this->draft)([['used_tyre_id' => $bad->id]]))->toThrow(BusinessRuleException::class);

    $sale = ($this->confirm)(($this->draft)([['used_tyre_id' => $good->id]]));
    expect($good->fresh()->status)->toBe(UsedTyreStatus::Sold)
        ->and($sale->lines->first()->unit_cost_cents)->toBe(15000)
        ->and($sale->total_ttc_cents)->toBe(30000);
});

it('freezes a confirmed invoice: no change, no deletion, not even in SQL (D-15)', function () {
    $sale = ($this->confirm)(($this->draft)([['product_id' => $this->tyre->id, 'quantity' => 1]]));

    expect(fn () => app(SaveSaleDraftAction::class)->execute(['site_id' => site('M01')->id, 'lines' => [['product_id' => $this->tyre->id, 'quantity' => 2]]], $this->seller, $sale))
        ->toThrow(BusinessRuleException::class);
    expect(fn () => $sale->delete())->toThrow(LogicException::class);
    expect(fn () => ($this->confirm)($sale))->toThrow(BusinessRuleException::class, __('sales.errors.not_draft'));
    // Savepoints so that each refused statement does not abort the test transaction.
    expect(fn () => DB::transaction(fn () => DB::table('sales')->where('id', $sale->id)->update(['total_ttc_cents' => 1])))->toThrow(QueryException::class);
    expect(fn () => DB::transaction(fn () => DB::table('sale_lines')->where('sale_id', $sale->id)->delete()))->toThrow(QueryException::class);
    expect(fn () => DB::transaction(fn () => DB::table('sales')->where('id', $sale->id)->delete()))->toThrow(QueryException::class);
    expect(DB::table('sales')->where('id', $sale->id)->value('total_ttc_cents'))->toBe(80000);
});

it('refuses sales to users without the permission or outside their sites', function () {
    $tech = userWithRole(RoleName::Technician, 'M01');
    expect(fn () => ($this->draft)([['product_id' => $this->tyre->id, 'quantity' => 1]], [], $tech))
        ->toThrow(BusinessRuleException::class, __('app.errors.forbidden'));

    $other = userWithRole(RoleName::Seller, 'M02');
    expect(fn () => ($this->draft)([['product_id' => $this->tyre->id, 'quantity' => 1]], [], $other))
        ->toThrow(BusinessRuleException::class, __('app.errors.forbidden'));
});

it('continues the numbering of the last real invoice and never goes back (D-49)', function () {
    $seq = app(SequenceService::class);
    $seq->setNextNumber(DocumentType::Invoice, now()->year, 1251);

    $sale = ($this->confirm)(($this->draft)([['product_id' => $this->tyre->id, 'quantity' => 1]]));
    expect($sale->number)->toBe('FA-'.now()->year.'-001251');

    expect(fn () => $seq->setNextNumber(DocumentType::Invoice, now()->year, 1251))->toThrow(BusinessRuleException::class);
    expect(fn () => $seq->setNextNumber(DocumentType::Invoice, now()->year, 100))->toThrow(BusinessRuleException::class);
});

it('uses the owner prefix for new numbers', function () {
    app(SettingsService::class)->set('documents.prefix.invoice', 'F');
    $sale = ($this->confirm)(($this->draft)([['product_id' => $this->tyre->id, 'quantity' => 1]]));

    expect($sale->number)->toBe('F-'.now()->year.'-000001');
});
