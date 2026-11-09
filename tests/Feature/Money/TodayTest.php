<?php

use App\Actions\Sales\ConfirmSaleAction;
use App\Actions\Sales\SaveSaleDraftAction;
use App\Enums\RoleName;
use App\Filament\Pages\Today;
use App\Models\Product;
use App\Services\Stock\StockService;
use App\Support\Money;
use Livewire\Livewire;

beforeEach(function () {
    $this->owner = owner();
    $p = Product::factory()->create(['price_retail_cents' => 120000]);
    app(StockService::class)->receive($p, site('M01'), 10, 50000);
    $d = app(SaveSaleDraftAction::class)->execute(['site_id' => site('M01')->id, 'lines' => [['product_id' => $p->id, 'quantity' => 1]]], $this->owner);
    app(ConfirmSaleAction::class)->execute($d, [['method' => 'card', 'amount_cents' => 120000]], $this->owner);
});

it('shows today on one page: sales per site, money by method, margin, monthly target (§16.1)', function () {
    $this->actingAs($this->owner);

    Livewire::test(Today::class)
        ->callAction('targets', ['target_'.site('M01')->id => '100 000'])
        ->assertSee(Money::format(120000))
        ->assertSee(Money::format(10000000))
        ->assertSee(__('today.events.sale_confirmed'));

    $this->actingAs(userWithRole(RoleName::SiteManager, 'M02'))->get(Today::getUrl())->assertOk()
        ->assertDontSee(Money::format(120000));
    $this->actingAs(userWithRole(RoleName::Seller, 'M01'))->get(Today::getUrl())->assertForbidden();
});

it('sends the evening summary to the owner (§16.2)', function () {
    $this->artisan('reports:evening-summary')->assertSuccessful();

    $note = $this->owner->notifications()->first();
    expect($note)->not->toBeNull()
        ->and(json_encode($note->data, JSON_UNESCAPED_UNICODE))->toContain('M01');
});

it('is installable on a phone (PWA manifest and service worker)', function () {
    $this->get('/admin/login')->assertOk()->assertSee('manifest.webmanifest', false);
    expect(json_decode(file_get_contents(public_path('manifest.webmanifest')), true)['display'])->toBe('standalone')
        ->and(file_exists(public_path('pwa/icon-512.png')))->toBeTrue();
});
