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

it('refuses to declare a database clean while customer records remain, without deleting them', function () {
    owner();
    $customer = Customer::factory()->create();

    $this->artisan('app:go-live-audit', ['--strict' => true])
        ->expectsOutputToContain('GO_LIVE_NOT_READY')
        ->assertFailed();

    expect($customer->fresh())->not->toBeNull();
});

it('accepts a new empty operating ledger with the owner, three sites and catalogues preserved', function () {
    owner();

    $this->artisan('app:go-live-audit', ['--strict' => true])
        ->expectsOutputToContain('GO_LIVE_CLEAN_BASE_VERIFIED')
        ->assertSuccessful();
});
