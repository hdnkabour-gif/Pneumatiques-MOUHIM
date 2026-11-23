<?php

use App\Actions\Sales\ConfirmSaleAction;
use App\Actions\Sales\SaveSaleDraftAction;
use App\Enums\RoleName;
use App\Enums\StockMovementType;
use App\Models\Customer;
use App\Models\Payment;
use App\Models\Product;
use App\Services\Stock\StockService;

/*
 * #21 (4): the same receipt submitted twice AT THE SAME INSTANT (two processes, two
 * PostgreSQL connections, same operation key) posts exactly once, and both callers
 * get the same payment back.
 */
it('posts one receipt when two concurrent requests carry the same key (4), 3 rounds', function () {
    $seller = userWithRole(RoleName::Seller, 'M01');
    $tyre = Product::factory()->tyre()->create(['price_retail_cents' => 10000, 'price_pro_cents' => 10000]);
    app(StockService::class)->receive($tyre, site('M01'), 10, 5000, StockMovementType::PurchaseReceipt);
    $bin = base_path('tests/Concurrency/bin/receive-payment.php');

    foreach (range(1, 3) as $round) {
        $customer = Customer::factory()->company()->create();
        $draft = app(SaveSaleDraftAction::class)->execute(['site_id' => site('M01')->id, 'customer_id' => $customer->id, 'lines' => [['product_id' => $tyre->id, 'quantity' => 1]]], $seller);
        app(ConfirmSaleAction::class)->execute($draft, [], $seller); // debt 100.00, no payment row
        $key = "rcpt-race-{$round}-".bin2hex(random_bytes(4));
        $start = microtime(true) + 3.0;
        $procs = [];
        foreach ([1, 2] as $_) {
            $p = proc_open([PHP_BINARY, $bin, (string) $customer->id, (string) site('M01')->id, (string) $seller->id, $key, '3000', (string) $start],
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

        expect(collect($out)->where('result', 'POSTED')->count())->toBe(2, "round {$round}: ".json_encode($out))
            ->and(collect($out)->pluck('payment')->unique()->count())->toBe(1, "round {$round}: both must return the SAME payment")
            ->and(Payment::query()->where('customer_id', $customer->id)->count())->toBe(1)
            ->and($customer->fresh()->outstandingCents())->toBe(7000);
    }
});
