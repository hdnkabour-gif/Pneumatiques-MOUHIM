<?php

use App\Enums\RoleName;
use App\Filament\Resources\TyreCatalogueReferences\TyreCatalogueReferenceResource;
use Database\Seeders\TyreOilReferenceCatalogueSeeder;
use Illuminate\Support\Facades\Artisan;

it('never blocks the deploy when the catalogue import fails', function () {
    Artisan::shouldReceive('call')->once()->andThrow(new RuntimeException('broken workbook'));

    expect(fn () => (new TyreOilReferenceCatalogueSeeder)->run())->not->toThrow(Throwable::class);
});

it('keeps the reference catalogues out of the seller menu', function () {
    $this->actingAs(userWithRole(RoleName::Seller, 'M01'));

    expect($this->get('/admin')->getContent())
        ->not->toContain(TyreCatalogueReferenceResource::getUrl());
});
