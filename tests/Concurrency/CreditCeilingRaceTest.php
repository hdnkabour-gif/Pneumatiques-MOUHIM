<?php

use App\Actions\Sales\SaveSaleDraftAction;
use App\Enums\RoleName;
use App\Enums\StockMovementType;
use App\Models\Customer;
use App\Models\Product;
use App\Services\Stock\StockService;

/*
 * #18 (PM-03): two sellers confirm credit sales for the SAME customer at the same instant.
 * Each sale alone fits the ceiling (700.00 ≤ 1,000.00); together they do not (1,400.00).
 * Real PostgreSQL, two real processes (two connections), committed data (DatabaseMigrations; TRUNCATE is refused by the append-only journal),
 * a shared start time as barrier. Exactly one may be confirmed without the owner's approval.
 * Reproduced first in NEXUS PR #23 on ea55329f: both were confirmed in 3/3 runs.
 */

function raceConfirm(int $saleA, int $saleB, int $sellerId): array
{
    $start = microtime(true) + 3.0;
    $bin = base_path('tests/Concurrency/bin/confirm-sale.php');
    $procs = [];
    foreach ([$saleA, $saleB] as $sale) {
        $p = proc_open([PHP_BINARY, $bin, (string) $sale, (string) $sellerId, (string) $start],
            [1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes);
        $procs[] = [$p, $pipes];
    }
    $out = [];
    foreach ($procs as [$p, $pipes]) {
        $stdout = stream_get_contents($pipes[1]);
        $stderr = stream_get_contents($pipes[2]);
        proc_close($p);
        $out[] = json_decode($stdout, true) ?? ['result' => 'ERROR', 'raw' => $stdout.$stderr];
    }

    return $out;
}

it('lets only one of two simultaneous credit sales through the customer ceiling (#18), 3 rounds', function () {
    $seller = userWithRole(RoleName::Seller, 'M01');
    $tyre = Product::factory()->tyre()->create(['price_retail_cents' => 70000, 'price_pro_cents' => 70000]);
    app(StockService::class)->receive($tyre, site('M01'), 20, 30000, StockMovementType::PurchaseReceipt);

    foreach (range(1, 3) as $round) {
        $customer = Customer::factory()->company(100000)->create(); // ceiling 1,000.00
        $draft = fn () => app(SaveSaleDraftAction::class)->execute(
            ['site_id' => site('M01')->id, 'customer_id' => $customer->id, 'lines' => [['product_id' => $tyre->id, 'quantity' => 1]]],
            $seller
        )->id;
        $results = raceConfirm($draft(), $draft(), $seller->id);

        $confirmed = collect($results)->where('result', 'CONFIRMED')->count();
        $refused = collect($results)->where('result', 'REFUSED')->where('error', 'ApprovalRequiredException')->count();
        expect($confirmed)->toBe(1, "round {$round}: ".json_encode($results))
            ->and($refused)->toBe(1, "round {$round}: ".json_encode($results))
            ->and($customer->fresh()->outstandingCents())->toBe(70000);
    }
});

it('still confirms both sales when the ceiling allows them together (no false refusal)', function () {
    $seller = userWithRole(RoleName::Seller, 'M01');
    $tyre = Product::factory()->tyre()->create(['price_retail_cents' => 70000, 'price_pro_cents' => 70000]);
    app(StockService::class)->receive($tyre, site('M01'), 10, 30000, StockMovementType::PurchaseReceipt);
    $customer = Customer::factory()->company(200000)->create(); // ceiling 2,000.00
    $draft = fn () => app(SaveSaleDraftAction::class)->execute(
        ['site_id' => site('M01')->id, 'customer_id' => $customer->id, 'lines' => [['product_id' => $tyre->id, 'quantity' => 1]]],
        $seller
    )->id;
    $results = raceConfirm($draft(), $draft(), $seller->id);

    expect(collect($results)->where('result', 'CONFIRMED')->count())->toBe(2, json_encode($results))
        ->and($customer->fresh()->outstandingCents())->toBe(140000);
});
