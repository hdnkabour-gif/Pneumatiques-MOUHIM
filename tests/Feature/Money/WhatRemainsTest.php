<?php

use App\Actions\Sales\ConfirmSaleAction;
use App\Actions\Sales\SaveSaleDraftAction;
use App\Enums\RoleName;
use App\Filament\Pages\WhatRemains;
use App\Models\Customer;
use App\Models\Product;
use App\Services\Stock\StockService;
use App\Support\Money;

it('shows the owner what is still open: debts with a WhatsApp reminder, cheques, quotes (§11)', function () {
    $owner = owner();
    $tyre = Product::factory()->tyre()->create(['price_pro_cents' => 70000]);
    app(StockService::class)->receive($tyre, site('M01'), 10, 50000);
    $pro = Customer::factory()->company(10000000)->create(['name' => 'Transport Atlas', 'phone' => '0661000000']);
    $draft = app(SaveSaleDraftAction::class)->execute(['site_id' => site('M01')->id, 'customer_id' => $pro->id, 'lines' => [['product_id' => $tyre->id, 'quantity' => 2]]], $owner);
    app(ConfirmSaleAction::class)->execute($draft, [['method' => 'cheque', 'amount_cents' => 40000, 'cheque_number' => '998877', 'bank' => 'BMCE']], $owner);

    $this->actingAs($owner)->get(WhatRemains::getUrl())->assertOk()
        ->assertSee('Transport Atlas')->assertSee(Money::format(100000))
        ->assertSee('wa.me/212661000000', false)
        ->assertSee('998877');
});

it('keeps the page for the owner and site managers only', function () {
    $this->actingAs(userWithRole(RoleName::Seller, 'M01'))->get(WhatRemains::getUrl())->assertForbidden();
    $this->actingAs(userWithRole(RoleName::SiteManager, 'M01'))->get(WhatRemains::getUrl())->assertOk();
});
