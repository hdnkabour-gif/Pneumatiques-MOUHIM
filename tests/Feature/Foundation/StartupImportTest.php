<?php

use App\Models\Customer;
use App\Models\Product;
use App\Models\StockLevel;
use App\Services\Imports\StartupImport;
use OpenSpout\Common\Entity\Row;
use OpenSpout\Writer\XLSX\Writer;

function xlsx(array $rows): string
{
    $path = storage_path('app/test-import-'.uniqid().'.xlsx');
    $w = new Writer;
    $w->openToFile($path);
    foreach ($rows as $r) {
        $w->addRow(Row::fromValues($r));
    }
    $w->close();

    return $path;
}

it('imports customers with their old debt, products, then opening stock (§17)', function () {
    $owner = owner();
    $import = app(StartupImport::class);

    $c = $import->check('customers', xlsx([StartupImport::TYPES['customers'],
        ['Transport Atlas', '0522 11 22 33', 'société', '001234567000089', 'Casablanca', '10000', '3000', '31/10/2026', '12345-أ-6'],
        ['Karim', '0661234567', 'particulier', '', '', '', '', '', ''],
    ]));
    expect($c['errors'])->toBe([]);
    expect($import->import('customers', $c['rows'], $owner))->toBe(['created' => 2, 'skipped' => 0]);
    $atlas = Customer::query()->where('ice', '001234567000089')->firstOrFail();
    expect($atlas->outstandingCents())->toBe(300000)->and($atlas->credit_limit_cents)->toBe(1000000)->and($atlas->vehicles)->toHaveCount(1);
    // Second run: nothing duplicated.
    expect($import->import('customers', $c['rows'], $owner))->toBe(['created' => 0, 'skipped' => 2]);

    $p = $import->check('products', xlsx([StartupImport::TYPES['products'],
        ['', 'Tourisme', 'Michelin', '850', '780', '', '520', 'piece', '205/55 R16 91V', '', 'PN-IMP-1'],
    ]));
    expect($p['errors'])->toBe([]);
    $import->import('products', $p['rows'], $owner);
    $tyre = Product::query()->where('sku', 'PN-IMP-1')->firstOrFail();
    expect($tyre->name)->toBe('Michelin 205/55 R16 91V')->and($tyre->tyreSpec->rim_diameter)->toEqual('16.0');

    $s = $import->check('stock', xlsx([StartupImport::TYPES['stock'], ['PN-IMP-1', 'm01', '8', '520', '2425']]));
    expect($s['errors'])->toBe([]);
    $import->import('stock', $s['rows'], $owner);
    expect(StockLevel::query()->where(['product_id' => $tyre->id, 'site_id' => site('M01')->id])->value('quantity'))->toBe(8);
});

it('refuses the whole file with clear line errors', function () {
    $check = app(StartupImport::class)->check('stock', xlsx([StartupImport::TYPES['stock'], ['NOPE', 'X1', '-2', 'abc', '9999']]));

    expect($check['errors'])->toHaveCount(5)
        ->and($check['errors'][0])->toStartWith(__('imports.line', ['line' => 2, 'error' => '']));
});

it('gives templates with an explanation sheet that is never imported', function () {
    $path = storage_path('app/test-template-'.uniqid().'.xlsx');
    app(StartupImport::class)->template('stock', $path);
    $check = app(StartupImport::class)->check('stock', $path);
    unlink($path);

    expect($check['errors'])->toBe([__('imports.empty')]);
});
