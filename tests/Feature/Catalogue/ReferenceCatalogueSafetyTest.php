<?php

use App\Enums\RoleName;
use App\Filament\Resources\OilCatalogueReferences\Pages\ListOilCatalogueReferences;
use App\Filament\Resources\TyreCatalogueReferences\TyreCatalogueReferenceResource;
use App\Models\OilCatalogueReference;
use App\Models\TyreCatalogueReference;
use Database\Seeders\TyreOilReferenceCatalogueSeeder;
use Illuminate\Support\Facades\Artisan;
use Livewire\Livewire;

it('never blocks the deploy when the catalogue import fails', function () {
    Artisan::shouldReceive('call')->once()->andThrow(new RuntimeException('broken workbook'));

    expect(fn () => (new TyreOilReferenceCatalogueSeeder)->run())->not->toThrow(Throwable::class);
});

it('keeps the reference catalogues out of the seller menu', function () {
    $this->actingAs(userWithRole(RoleName::Seller, 'M01'));

    expect($this->get('/admin')->getContent())
        ->not->toContain(TyreCatalogueReferenceResource::getUrl());
});

it('lets a manager correct a reference row and keeps the correction after a new import', function () {
    $row = OilCatalogueReference::query()->where('record_type', 'model')->firstOrFail();

    $this->actingAs(owner());
    Livewire::test(ListOilCatalogueReferences::class)
        ->callTableAction('edit', $row, data: [
            'brand' => $row->brand,
            'model' => $row->model,
            'verification_status' => 'checked',
            'specifications' => ['Norme ACEA' => 'C3', 'Note' => 'Lu sur le bidon'],
        ])
        ->assertHasNoTableActionErrors();

    expect($row->fresh()->verification_status)->toBe('checked')
        ->and($row->fresh()->specifications)->toBe(['Norme ACEA' => 'C3', 'Note' => 'Lu sur le bidon']);

    Artisan::call('catalogue:import-tyre-oil');

    expect($row->fresh()->verification_status)->toBe('checked')
        ->and($row->fresh()->specifications['Note'])->toBe('Lu sur le bidon');
});

it('refuses the correction to a user who does not manage compatibilities', function () {
    $row = TyreCatalogueReference::query()->firstOrFail();
    $seller = userWithRole(RoleName::Seller, 'M01');

    expect($seller->can('update', $row))->toBeFalse()
        ->and($seller->can('delete', $row))->toBeFalse()
        ->and(owner()->can('update', $row))->toBeTrue()
        ->and(owner()->can('delete', $row))->toBeFalse();
});
