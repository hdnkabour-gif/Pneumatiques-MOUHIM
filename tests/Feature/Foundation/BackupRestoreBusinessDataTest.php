<?php

use App\Actions\Backups\RestoreDatabaseBackupAction;
use App\Actions\Money\CustomerAccountActions;
use App\Actions\Sales\ConfirmSaleAction;
use App\Actions\Sales\SaveSaleDraftAction;
use App\Actions\Stock\ReceiveStockAction;
use App\Actions\Transfers\ReceiveTransferAction;
use App\Actions\Transfers\SendTransferAction;
use App\Enums\RoleName;
use App\Models\Customer;
use App\Models\Product;
use App\Models\Sale;
use App\Services\Stock\StockService;
use App\Services\Stock\StockVerifier;
use Database\Seeders\DatabaseSeeder;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Spatie\Permission\PermissionRegistrar;

/*
| A realistic business day on the three sites, backed up and restored into a new database:
| every row, sequence value and the exact definition of every constraint, trigger, index and
| function must come back identical, and the restored copy must keep working.
| Runs on scratch databases with committed data (pg_dump cannot see the test transaction).
*/

beforeEach(function () {
    $base = config('database.connections.pgsql');
    config([
        'database.connections.pg_admin' => [...$base, 'database' => 'postgres'],
        'database.connections.business_source' => [...$base, 'database' => 'mouhim_business_test'],
        'database.connections.business_restored' => [...$base, 'database' => 'mouhim_business_restored'],
    ]);

    $backupConfig = config('backup');
    $backupConfig['backup']['source']['databases'] = ['business_source'];
    $backupConfig['backup']['source']['files']['include'] = [];
    $backupConfig['backup']['destination']['disks'] = ['backups_local'];
    config(['backup_business_test' => $backupConfig]);

    foreach (['mouhim_business_test', 'mouhim_business_restored'] as $database) {
        DB::connection('pg_admin')->statement("DROP DATABASE IF EXISTS {$database} WITH (FORCE)");
    }
    DB::connection('pg_admin')->statement('CREATE DATABASE mouhim_business_test');
    Artisan::call('migrate', ['--database' => 'business_source', '--force' => true]);
    Storage::fake('backups_local');
});

afterEach(function () {
    DB::setDefaultConnection('pgsql'); // before the test transaction is rolled back
    DB::purge('business_source');
    DB::purge('business_restored');
    foreach (['mouhim_business_test', 'mouhim_business_restored'] as $database) {
        DB::connection('pg_admin')->statement("DROP DATABASE IF EXISTS {$database} WITH (FORCE)");
    }
});

/** Makes the application work on the given connection (models, actions, seeders). */
function useConnection(string $connection): void
{
    DB::setDefaultConnection($connection);
    app(PermissionRegistrar::class)->forgetCachedPermissions();
}

/**
 * Exact content of a database: per table its row count and an MD5 of every row, the value of
 * every sequence, and the exact definition of every constraint, trigger, index and function.
 */
function databaseFingerprint(string $connection): array
{
    $db = DB::connection($connection);
    $tables = $db->table('pg_tables')->where('schemaname', 'public')->orderBy('tablename')->pluck('tablename');

    $fingerprint = [];
    foreach ($tables as $table) {
        $row = $db->selectOne("SELECT count(*) AS n, md5(coalesce(string_agg(t::text, '|' ORDER BY t::text), '')) AS h FROM \"{$table}\" t");
        $fingerprint['tables'][$table] = "{$row->n}:{$row->h}";
    }
    $fingerprint['sequences'] = $db->table('pg_sequences')->where('schemaname', 'public')->orderBy('sequencename')
        ->pluck('last_value', 'sequencename')->all();

    $definitions = fn (string $sql) => collect($db->select($sql))->map(fn ($r) => $r->definition)->sort()->values()->all();
    $fingerprint['constraints'] = $definitions("SELECT c.conrelid::regclass || ' ' || c.conname || ' ' || pg_get_constraintdef(c.oid) AS definition
        FROM pg_constraint c JOIN pg_namespace s ON s.oid = c.connamespace WHERE s.nspname = 'public'");
    $fingerprint['triggers'] = $definitions("SELECT pg_get_triggerdef(t.oid) AS definition
        FROM pg_trigger t JOIN pg_class c ON c.oid = t.tgrelid JOIN pg_namespace s ON s.oid = c.relnamespace WHERE s.nspname = 'public' AND NOT t.tgisinternal");
    $fingerprint['indexes'] = $definitions("SELECT indexdef AS definition FROM pg_indexes WHERE schemaname = 'public'");
    $fingerprint['functions'] = $definitions("SELECT pg_get_functiondef(p.oid) AS definition
        FROM pg_proc p JOIN pg_namespace s ON s.oid = p.pronamespace WHERE s.nspname = 'public'");

    return $fingerprint;
}

it('backs up and restores a three-site business day figure for figure', function () {
    useConnection('business_source');
    test()->seed(DatabaseSeeder::class);

    $owner = owner();
    $storekeeper = userWithRole(RoleName::Storekeeper, 'DEP');
    $sellerM01 = userWithRole(RoleName::Seller, 'M01');
    $sellerM02 = userWithRole(RoleName::Seller, 'M02');
    $tyre = Product::factory()->tyre(205, 55, '16')->create(['price_retail_cents' => 80000, 'price_pro_cents' => 70000]);
    $oil = Product::factory()->create(['name' => 'Huile 5W30 bidon 5 L', 'price_retail_cents' => 25000, 'price_pro_cents' => 22000]);

    // Purchases received at the depot, then sent to the shops (one transfer still on the road).
    app(ReceiveStockAction::class)->execute($tyre, site('DEP'), 100, 45000, $storekeeper, dotWeek: 10, dotYear: 2026, supplier: 'Fournisseur A');
    app(ReceiveStockAction::class)->execute($oil, site('DEP'), 40, 9000, $storekeeper, supplier: 'Fournisseur B');
    $toM01 = app(SendTransferAction::class)->execute(site('DEP'), site('M01'), [$tyre->id => 20, $oil->id => 10], $storekeeper);
    $toM02 = app(SendTransferAction::class)->execute(site('DEP'), site('M02'), [$tyre->id => 15], $storekeeper);
    app(ReceiveTransferAction::class)->execute($toM01, $owner);
    app(ReceiveTransferAction::class)->execute($toM02, $owner);
    app(SendTransferAction::class)->execute(site('DEP'), site('M02'), [$tyre->id => 5], $storekeeper); // in transit

    // A cash sale at M01, and a credit sale at M02 for a company with an opening balance.
    $cashSale = app(SaveSaleDraftAction::class)->execute(['site_id' => site('M01')->id, 'lines' => [
        ['product_id' => $tyre->id, 'quantity' => 4], ['product_id' => $oil->id, 'quantity' => 1],
    ]], $sellerM01);
    $cashSale = app(ConfirmSaleAction::class)->execute($cashSale, [['method' => 'cash', 'amount_cents' => $cashSale->total_ttc_cents]], $sellerM01);

    $company = Customer::factory()->company(10000000)->create(['name' => 'Transports Atlas (fictif)']);
    $accounts = app(CustomerAccountActions::class);
    $accounts->addOpeningBalance($company, 300000, '2026-10-31', 'Cahier', $owner);
    $creditSale = app(SaveSaleDraftAction::class)->execute(['site_id' => site('M02')->id, 'customer_id' => $company->id, 'lines' => [
        ['product_id' => $tyre->id, 'quantity' => 2],
    ]], $owner);
    $creditSale = app(ConfirmSaleAction::class)->execute($creditSale, [], $owner);
    $accounts->receive($company, site('M02'), ['method' => 'cheque', 'amount_cents' => 200000, 'cheque_number' => '000123', 'bank' => 'CIH'], $sellerM02);

    $debt = $company->fresh()->outstandingCents();
    expect($debt)->toBe(300000 + 140000 - 200000)
        ->and(app(StockVerifier::class)->differences())->toBe([]);

    // Backup, then restore into a new database.
    $before = databaseFingerprint('business_source');
    expect(Artisan::call('backup:run', ['--only-db' => true, '--config' => 'backup_business_test', '--disable-notifications' => true]))->toBe(0);
    $restore = app(RestoreDatabaseBackupAction::class);
    $restore->intoNewDatabase('backups_local', $restore->available('backups_local')[0], 'mouhim_business_restored', 'business_source');

    // Every table, sequence, foreign key, trigger and index came back identical.
    $after = databaseFingerprint('business_restored');
    expect($after)->toBe($before)
        ->and(count($before['tables']))->toBeGreaterThan(50)
        ->and(collect($before['constraints'])->filter(fn ($d) => str_contains($d, 'FOREIGN KEY'))->count())->toBeGreaterThan(50)
        ->and($before['triggers'])->not->toBeEmpty()
        ->and($before['functions'])->not->toBeEmpty()
        ->and(count($before['indexes']))->toBeGreaterThan(50);

    // The restored copy works: stock ledger consistent, debts and invoices intact,
    // and a new invoice does not reuse a number.
    useConnection('business_restored');
    $stock = app(StockService::class);
    expect(app(StockVerifier::class)->differences())->toBe([])
        ->and($stock->quantityAt($tyre, site('DEP')))->toBe(60)
        ->and($stock->quantityAt($tyre, site('M01')))->toBe(16)
        ->and($stock->quantityAt($tyre, site('M02')))->toBe(13)
        ->and($stock->quantityAt($oil, site('M01')))->toBe(9)
        ->and(Customer::query()->find($company->id)->outstandingCents())->toBe($debt)
        ->and(DB::table('issued_invoice_documents')->whereIn('sale_id', [$cashSale->id, $creditSale->id])->count())->toBe(2);

    // The archived invoices are the same bytes (table hashes above) and complete PDF files.
    foreach ([$cashSale, $creditSale] as $sale) {
        $pdf = base64_decode(DB::table('issued_invoice_documents')->where('sale_id', $sale->id)->value('pdf_base64'), true);
        expect($pdf)->toStartWith('%PDF-')
            ->and(rtrim($pdf))->toEndWith('%%EOF')
            ->and(strlen($pdf))->toBeGreaterThan(1000);
    }
    test()->artisan('stock:verify')->assertSuccessful();

    $next = app(SaveSaleDraftAction::class)->execute(['site_id' => site('M01')->id, 'lines' => [['product_id' => $tyre->id, 'quantity' => 1]]], $sellerM01);
    $next = app(ConfirmSaleAction::class)->execute($next, [['method' => 'cash', 'amount_cents' => $next->total_ttc_cents]], $sellerM01);
    expect(Sale::query()->where('number', $next->number)->count())->toBe(1)
        ->and($next->number)->not->toBeIn([$cashSale->number, $creditSale->number]);

    // The source database was never touched by the restore.
    useConnection('business_source');
    expect(databaseFingerprint('business_source'))->toBe($before);
});
