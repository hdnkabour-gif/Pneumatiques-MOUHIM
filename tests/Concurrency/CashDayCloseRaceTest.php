<?php

use App\Actions\Sales\ConfirmSaleAction;
use App\Actions\Sales\SaveSaleDraftAction;
use App\Enums\RoleName;
use App\Enums\StockMovementType;
use App\Models\CashDay;
use App\Models\Customer;
use App\Models\Expense;
use App\Models\Payment;
use App\Models\Product;
use App\Models\Sale;
use App\Services\Money\CashRegister;
use App\Services\Stock\StockService;
use Illuminate\Support\Facades\DB;

/*
 * #17 (PM-02): closing the cash day races with every operation that moves cash.
 *
 * Deterministic barriers on REAL PostgreSQL: a separate connection holds a row lock that
 * the operation needs AFTER it has taken the cash-day lock (customer, product or site row),
 * so the operation is provably mid-transaction; then the close is started, and we wait
 * until PostgreSQL itself shows the expected processes queued behind the barrier
 * (pg_blocking_pids) — or the close already finished (what the unfixed code does).
 *
 * The invariant checked after every schedule: the day is closed, and the expected amount
 * recorded at close == the day's summary recomputed afterwards. Every operation is either
 * counted in the close (POSTED before it) or refused with nothing written. No ERROR
 * (deadlock, lock timeout) in any process.
 */

/** @return array{0: resource, 1: array<int, resource>} */
function cashOp(string $op, int ...$args): array
{
    $p = proc_open([PHP_BINARY, base_path('tests/Concurrency/bin/cash-op.php'), $op, ...array_map('strval', $args)],
        [1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes);

    return [$p, $pipes];
}

/** @param array{0: resource, 1: array<int, resource>} $h */
function cashResult(array $h): array
{
    [$p, $pipes] = $h;
    $stdout = stream_get_contents($pipes[1]);
    $stderr = stream_get_contents($pipes[2]);
    proc_close($p);

    return json_decode($stdout, true) ?? ['result' => 'ERROR', 'raw' => substr($stdout.$stderr, 0, 600)];
}

/** A third connection holding row locks until released. */
function cashBarrier(string ...$lockSql): array
{
    $c = config('database.connections.pgsql');
    $pdo = new PDO("pgsql:host={$c['host']};port={$c['port']};dbname={$c['database']}", $c['username'], $c['password']);
    $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
    $pdo->exec('BEGIN');
    foreach ($lockSql as $sql) {
        $pdo->query($sql);
    }

    return [$pdo, (int) $pdo->query('SELECT pg_backend_pid()')->fetchColumn()];
}

/**
 * Wait until $n backends are blocked (directly or transitively) behind the barrier pid, or
 * until every $done process has exited. Returns how many were behind the barrier.
 *
 * @param  array<int, array{0: resource, 1: array}>  $done
 */
function cashWaitBehind(int $pid, int $n, array $done = []): int
{
    $deadline = microtime(true) + 20;
    do {
        $rows = DB::select("SELECT pid, pg_blocking_pids(pid) AS by FROM pg_stat_activity WHERE datname = current_database() AND wait_event_type = 'Lock'");
        $behind = [$pid => true];
        for ($grew = true; $grew;) {
            $grew = false;
            foreach ($rows as $r) {
                $by = array_filter(explode(',', trim((string) $r->by, '{}')), 'strlen');
                if (! isset($behind[$r->pid]) && array_intersect(array_map('intval', $by), array_keys($behind))) {
                    $behind[$r->pid] = true;
                    $grew = true;
                }
            }
        }
        if (count($behind) - 1 >= $n) {
            return count($behind) - 1;
        }
        if ($done !== [] && collect($done)->every(fn ($h) => ! proc_get_status($h[0])['running'])) {
            return count($behind) - 1;
        }
        usleep(20000);
    } while (microtime(true) < $deadline);

    throw new RuntimeException("barrier: expected {$n} processes behind pid {$pid}");
}

function cashInvariant(): CashDay
{
    $day = CashDay::query()->where('site_id', site('M01')->id)->where('business_date', app(CashRegister::class)->today()->toDateString())->firstOrFail();
    expect($day->closed_at)->not->toBeNull()
        ->and($day->expected_cents)->toBe(app(CashRegister::class)->summary(site('M01'))['expected'],
            'money was posted to the day outside the close');

    return $day;
}

beforeEach(function () {
    $this->manager = userWithRole(RoleName::SiteManager, 'M01');
    $this->seller = userWithRole(RoleName::Seller, 'M01');
    $this->tyre = Product::factory()->tyre()->create(['price_retail_cents' => 10000, 'price_pro_cents' => 10000]);
    app(StockService::class)->receive($this->tyre, site('M01'), 20, 5000, StockMovementType::PurchaseReceipt);
    app(CashRegister::class)->setFloat(site('M01'), 50000, $this->manager); // the day row exists, float 500.00
    $this->draft = fn (?Customer $c = null) => app(SaveSaleDraftAction::class)->execute(['site_id' => site('M01')->id,
        'customer_id' => $c?->id, 'lines' => [['product_id' => $this->tyre->id, 'quantity' => 1]]], $this->seller);
    $this->debtor = function () {
        $c = Customer::factory()->company()->create();
        app(ConfirmSaleAction::class)->execute(($this->draft)($c), [], $this->seller); // debt, no cash

        return $c;
    };
    $this->cashInvoice = function () {
        $d = ($this->draft)();

        return app(ConfirmSaleAction::class)->execute($d, [['method' => 'cash', 'amount_cents' => $d->total_ttc_cents]], $this->seller)->fresh('lines');
    };
});

/** Runs [$writers] mid-transaction behind a barrier, then a close; returns all results. */
function cashRaceWritersThenClose(array $barrierSql, array $writers, int $managerId): array
{
    [$pdo, $pid] = cashBarrier(...$barrierSql);
    $handles = [];
    foreach ($writers as $w) {
        $handles[] = cashOp(...$w);
    }
    cashWaitBehind($pid, count($writers));
    $close = cashOp('close', site('M01')->id, $managerId, 0);
    $behind = cashWaitBehind($pid, count($writers) + 1, [$close]);
    $pdo->exec('COMMIT');

    return ['close' => cashResult($close), 'writers' => array_map('cashResult', $handles), 'close_was_queued' => $behind > count($writers)];
}

it('counts a cash receipt that was mid-transaction when the close started', function () {
    $c = ($this->debtor)();
    $r = cashRaceWritersThenClose(["SELECT id FROM customers WHERE id = {$c->id} FOR UPDATE"],
        [['receipt', $c->id, site('M01')->id, $this->seller->id, 3000]], $this->manager->id);

    expect($r['close']['result'])->toBe('POSTED', json_encode($r))
        ->and($r['writers'][0]['result'])->toBe('POSTED', json_encode($r))
        ->and($r['close_was_queued'])->toBeTrue('the close must wait for the receipt in flight');
    expect(cashInvariant()->expected_cents)->toBe(50000 + 3000);
});

it('counts a cash sale that was mid-transaction when the close started', function () {
    $c = Customer::factory()->company()->create();
    $sale = ($this->draft)($c);
    $r = cashRaceWritersThenClose(["SELECT id FROM customers WHERE id = {$c->id} FOR UPDATE"],
        [['sale', $sale->id, $this->seller->id, $sale->total_ttc_cents]], $this->manager->id);

    expect($r['writers'][0]['result'])->toBe('POSTED', json_encode($r))->and($r['close']['result'])->toBe('POSTED');
    expect(cashInvariant()->expected_cents)->toBe(50000 + $sale->total_ttc_cents);
});

it('counts a cash credit note (refund) that was mid-transaction when the close started', function () {
    $invoice = ($this->cashInvoice)();
    $r = cashRaceWritersThenClose(["SELECT id FROM products WHERE id = {$this->tyre->id} FOR UPDATE"],
        [['credit_note', $invoice->id, $invoice->lines->first()->id, owner()->id]], $this->manager->id);

    expect($r['writers'][0]['result'])->toBe('POSTED', json_encode($r))->and($r['close']['result'])->toBe('POSTED');
    expect(cashInvariant()->expected_cents)->toBe(50000 + $invoice->total_ttc_cents - $invoice->total_ttc_cents);
});

it('counts a cash expense that was mid-transaction when the close started', function () {
    $r = cashRaceWritersThenClose(['SELECT id FROM sites WHERE id = '.site('M01')->id.' FOR UPDATE'],
        [['expense', site('M01')->id, $this->seller->id, 4000]], $this->manager->id);

    expect($r['writers'][0]['result'])->toBe('POSTED', json_encode($r))->and($r['close']['result'])->toBe('POSTED');
    expect(cashInvariant()->expected_cents)->toBe(50000 - 4000);
});

it('counts a MIX of sale, receipt, refund and expense all in flight together, without deadlock', function () {
    $debtor = ($this->debtor)();
    $buyer = Customer::factory()->company()->create();
    $sale = ($this->draft)($buyer);
    $invoice = ($this->cashInvoice)();
    $r = cashRaceWritersThenClose([
        "SELECT id FROM customers WHERE id IN ({$debtor->id}, {$buyer->id}) ORDER BY id FOR UPDATE",
        "SELECT id FROM products WHERE id = {$this->tyre->id} FOR UPDATE",
        'SELECT id FROM sites WHERE id = '.site('M01')->id.' FOR UPDATE',
    ], [
        ['receipt', $debtor->id, site('M01')->id, $this->seller->id, 2500],
        ['sale', $sale->id, $this->seller->id, $sale->total_ttc_cents],
        ['credit_note', $invoice->id, $invoice->lines->first()->id, owner()->id],
        ['expense', site('M01')->id, $this->seller->id, 1500],
    ], $this->manager->id);

    expect(collect($r['writers'])->pluck('result')->all())->toBe(['POSTED', 'POSTED', 'POSTED', 'POSTED'], json_encode($r))
        ->and($r['close']['result'])->toBe('POSTED');
    // float + receipt + sale + (invoice − its refund) − expense
    expect(cashInvariant()->expected_cents)->toBe(50000 + 2500 + $sale->total_ttc_cents - 1500);
});

it('refuses receipt, sale, expense and float arriving while the close is in flight; nothing is written', function () {
    $debtor = ($this->debtor)();
    $buyer = Customer::factory()->company()->create();
    $sale = ($this->draft)($buyer);
    $dayId = app(CashRegister::class)->day(site('M01'))->id;
    $paymentsBefore = Payment::query()->count();

    [$pdo, $pid] = cashBarrier("SELECT id FROM cash_days WHERE id = {$dayId} FOR UPDATE");
    $close = cashOp('close', site('M01')->id, $this->manager->id, 0);
    cashWaitBehind($pid, 1);                                  // the close is queued first
    $late = [
        cashOp('receipt', $debtor->id, site('M01')->id, $this->seller->id, 2000),
        cashOp('sale', $sale->id, $this->seller->id, $sale->total_ttc_cents),
        cashOp('expense', site('M01')->id, $this->seller->id, 700),
        cashOp('float', site('M01')->id, $this->manager->id, 99900),
    ];
    cashWaitBehind($pid, 5, $late);
    $pdo->exec('COMMIT');
    $closeResult = cashResult($close);
    $results = array_map('cashResult', $late);

    expect($closeResult['result'])->toBe('POSTED', json_encode($closeResult))
        ->and(collect($results)->pluck('result')->all())->toBe(['REFUSED', 'REFUSED', 'REFUSED', 'REFUSED'], json_encode($results))
        ->and(collect($results)->pluck('message')->unique()->all())->toBe([__('cash.errors.closed')]);
    expect(Payment::query()->count())->toBe($paymentsBefore)
        ->and(Expense::query()->count())->toBe(0)
        ->and(Sale::query()->find($sale->id)->isDraft())->toBeTrue();
    $day = cashInvariant();
    expect($day->opening_float_cents)->toBe(50000)->and($day->expected_cents)->toBe(50000);
});

it('refuses to check the cash day outside a transaction: the lock would be released at once (#17)', function () {
    expect(DB::transactionLevel())->toBe(0)
        ->and(fn () => app(CashRegister::class)->assertOpen(site('M01')->id))->toThrow(LogicException::class);
});

it('a cash receipt and a cash refund on the same open invoice, with NO cash-day row yet, do not deadlock (Codex P2)', function () {
    $c = ($this->debtor)();                                      // credit invoice: no cash moved, so…
    $invoice = Sale::query()->where('customer_id', $c->id)->firstOrFail()->load('lines');
    DB::table('cash_days')->delete();                            // …and no cash-day row exists for today
    expect(CashDay::query()->count())->toBe(0);

    // The receipt creates today's row (uncommitted) and is held on the customer lock; the refund
    // then arrives for the invoice the receipt will allocate to.
    [$pdo, $pid] = cashBarrier("SELECT id FROM customers WHERE id = {$c->id} FOR UPDATE");
    $receipt = cashOp('receipt', $c->id, site('M01')->id, $this->seller->id, 3000);
    cashWaitBehind($pid, 1);
    $refund = cashOp('credit_note', $invoice->id, $invoice->lines->first()->id, owner()->id);
    cashWaitBehind($pid, 2, [$refund]);
    $pdo->exec('COMMIT');
    $results = [cashResult($receipt), cashResult($refund)];

    expect(collect($results)->pluck('result')->all())->toBe(['POSTED', 'POSTED'], json_encode($results));
    expect(CashDay::query()->count())->toBe(1);
    $s = app(CashRegister::class)->summary(site('M01'));
    expect($s['cash_in'])->toBe(3000)->and($s['refunds'])->toBe($invoice->total_ttc_cents);
});
