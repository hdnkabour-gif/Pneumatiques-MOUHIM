<?php

namespace Tests\Feature\Catalogue;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class TyreOilReferenceCatalogueImportTest extends TestCase
{
    use RefreshDatabase;

    public function test_workbook_reference_rows_import_without_creating_sellable_products_or_stock(): void
    {
        $this->artisan('catalogue:import-tyre-oil')->assertSuccessful();

        $this->assertDatabaseCount('tyre_catalogue_references', 120);
        $this->assertDatabaseCount('oil_catalogue_references', 46);
        $this->assertSame(0, DB::table('products')->count());
        $this->assertSame(0, DB::table('stock_movements')->count());
    }

    public function test_reference_import_is_idempotent(): void
    {
        Artisan::call('catalogue:import-tyre-oil');
        Artisan::call('catalogue:import-tyre-oil');

        $this->assertDatabaseCount('tyre_catalogue_references', 120);
        $this->assertDatabaseCount('oil_catalogue_references', 46);
    }
}
