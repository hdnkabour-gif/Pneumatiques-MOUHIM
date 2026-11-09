<?php

use App\Actions\Sales\ConfirmSaleAction;
use App\Actions\Sales\SaveSaleDraftAction;
use App\Enums\RoleName;
use App\Filament\Pages\AccountingExportPage;
use App\Models\Product;
use App\Services\Reports\AccountingExport;
use App\Services\Stock\StockService;
use OpenSpout\Reader\XLSX\Reader;

it('exports the month for the accountant: sales with VAT, payments (owner only)', function () {
    $owner = owner();
    $p = Product::factory()->create(['price_retail_cents' => 120000]);
    app(StockService::class)->receive($p, site('M01'), 5, 50000);
    $d = app(SaveSaleDraftAction::class)->execute(['site_id' => site('M01')->id, 'lines' => [['product_id' => $p->id, 'quantity' => 1]]], $owner);
    $sale = app(ConfirmSaleAction::class)->execute($d, [['method' => 'cash', 'amount_cents' => 120000]], $owner);

    $path = storage_path('app/test-export-'.uniqid().'.xlsx');
    app(AccountingExport::class)->write(now(config('mouhim.timezone'))->toImmutable(), $path);

    $reader = new Reader;
    $reader->open($path);
    $sheets = [];
    foreach ($reader->getSheetIterator() as $sheet) {
        foreach ($sheet->getRowIterator() as $row) {
            $sheets[$sheet->getName()][] = $row->toArray();
        }
    }
    $reader->close();
    unlink($path);

    expect(array_keys($sheets))->toBe(['Ventes', 'TVA collectée', 'Achats', 'Encaissements', 'Dépenses'])
        ->and($sheets['Ventes'][1][1])->toBe($sale->number)
        ->and($sheets['Ventes'][1][8])->toEqual(1200)
        ->and($sheets['TVA collectée'][1])->toEqual([20, 1000, 200])
        ->and($sheets['Encaissements'][1][3])->toEqual(1200);

    $this->actingAs(userWithRole(RoleName::SiteManager, 'M01'))->get(AccountingExportPage::getUrl())->assertForbidden();
    $this->actingAs($owner)->get(AccountingExportPage::getUrl())->assertOk();
});
