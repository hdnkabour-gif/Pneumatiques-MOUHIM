<?php

use App\Models\Customer;
use App\Models\Sale;

it('runs a read-only go-live audit without deleting operational or reference records', function () {
    $owner = owner();
    $customer = Customer::factory()->create();

    $this->artisan('app:go-live-audit')
        ->expectsOutput('READ-ONLY GO-LIVE AUDIT — NO DATA HAS BEEN DELETED')
        ->assertSuccessful();

    expect($customer->fresh())->not->toBeNull()
        ->and($owner->fresh())->not->toBeNull()
        ->and(Sale::query()->count())->toBe(0);
});
