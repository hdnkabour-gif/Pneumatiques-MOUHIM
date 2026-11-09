<?php

use App\Actions\Sales\ConfirmSaleAction;
use App\Actions\Sales\SaveSaleDraftAction;
use App\Enums\RoleName;
use App\Exceptions\BusinessRuleException;
use App\Filament\Pages\CashDesk;
use App\Models\Expense;
use App\Models\Product;
use App\Services\Money\CashRegister;
use App\Services\Stock\StockService;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Livewire\Livewire;

beforeEach(function () {
    $this->cash = app(CashRegister::class);
    $this->manager = userWithRole(RoleName::SiteManager, 'M01');
    $this->seller = userWithRole(RoleName::Seller, 'M01');
    $this->product = Product::factory()->create(['price_retail_cents' => 50000]);
    app(StockService::class)->receive($this->product, site('M01'), 20, 20000);
    $this->sellCash = function (int $amount = 50000) {
        $d = app(SaveSaleDraftAction::class)->execute(['site_id' => site('M01')->id, 'lines' => [['product_id' => $this->product->id, 'quantity' => 1]]], $this->seller);

        return app(ConfirmSaleAction::class)->execute($d, [['method' => 'cash', 'amount_cents' => $amount]], $this->seller);
    };
});

it('computes what must be in the drawer: float + cash in − expenses (§21 caisse)', function () {
    $this->cash->setFloat(site('M01'), 50000, $this->manager);
    ($this->sellCash)();
    ($this->sellCash)();
    $this->cash->addExpense(site('M01'), ['category' => 'food', 'amount_cents' => 6000, 'description' => 'Déjeuner atelier'], $this->seller);

    expect($this->cash->summary(site('M01')))->toMatchArray(['float' => 50000, 'cash_in' => 100000, 'expenses' => 6000, 'expected' => 144000])
        ->and($this->cash->summary(site('M02'))['expected'])->toBe(0);
});

it('closes the day with the gap, locks it, and only the owner reopens it (D-29)', function () {
    ($this->sellCash)();
    expect(fn () => $this->cash->close(site('M01'), 50000, null, $this->seller))->toThrow(BusinessRuleException::class);

    $day = $this->cash->close(site('M01'), 49000, 'Pièce manquante', $this->manager);
    expect($day->expected_cents)->toBe(50000)->and($day->difference_cents)->toBe(-1000);

    expect(fn () => ($this->sellCash)())->toThrow(BusinessRuleException::class, __('cash.errors.closed'));
    expect(fn () => $this->cash->addExpense(site('M01'), ['category' => 'other', 'amount_cents' => 100, 'description' => 'x'], $this->seller))
        ->toThrow(BusinessRuleException::class);

    expect(fn () => $this->cash->reopen(site('M01'), 'Vente oubliée', $this->manager))->toThrow(BusinessRuleException::class);
    $this->cash->reopen(site('M01'), 'Vente oubliée', owner());
    expect(($this->sellCash)()->number)->not->toBeNull();
});

it('records an expense with its photo from the cash page; storekeepers have no cash page', function () {
    Storage::fake('public');
    $this->seller->update(['default_site_id' => site('M01')->id]);
    $this->actingAs($this->seller);

    Livewire::test(CashDesk::class)
        ->callAction('expense', ['amount_cents' => '35,50', 'category' => 'transport', 'description' => 'Taxi pièces',
            'photo' => [UploadedFile::fake()->image('ticket.jpg')], 'paid_in_cash' => true])
        ->assertHasNoActionErrors();

    $expense = Expense::query()->firstOrFail();
    expect($expense->amount_cents)->toBe(3550)->and($expense->photo)->toStartWith('expenses/');

    $this->get(CashDesk::getUrl())->assertOk()->assertSee('Taxi pièces');
    $this->actingAs(userWithRole(RoleName::Storekeeper, 'DEP'))->get(CashDesk::getUrl())->assertForbidden();
});
