<?php

use App\Actions\Vehicles\FitmentStatusAction;
use App\Actions\Vehicles\ImportReferenceWorkbookAction;
use App\Enums\CompatibilityStatus;
use App\Enums\RoleName;
use App\Exceptions\BusinessRuleException;
use App\Filament\Pages\VehicleLookup;
use App\Filament\Resources\Vehicles\TyreFitments\Pages\EditTyreFitment;
use App\Filament\Resources\Vehicles\TyreFitments\Pages\ListTyreFitments;
use App\Filament\Resources\Vehicles\TyreFitments\Pages\ViewTyreFitment;
use App\Models\Activity;
use App\Models\CompatibilitySource;
use App\Models\OilFitment;
use App\Models\Product;
use App\Models\TyreFitment;
use App\Models\VehicleMake;
use App\Models\VehicleModel;
use App\Services\Stock\StockService;
use Livewire\Livewire;

function workbook(): string
{
    return base_path('docs/data/PNEU_MOUHIM_Manager_Catalogue_Operations_V1_3.xlsx');
}

function importWorkbook(): array
{
    return app(ImportReferenceWorkbookAction::class)->execute(workbook(), owner());
}

it('imports the reference workbook V1.3', function () {
    $report = importWorkbook();

    expect($report['errors'])->toBe([])
        ->and(VehicleMake::query()->count())->toBe(39)
        ->and(VehicleModel::query()->count())->toBe(263)
        ->and(CompatibilitySource::query()->count())->toBe(26)
        ->and(TyreFitment::query()->count())->toBe(13)
        ->and(OilFitment::query()->count())->toBe(11);

    $logan = TyreFitment::query()->where('external_id', 'TIR-DAC-LOG-ESS')->firstOrFail();
    expect($logan->sizeLabel())->toBe('185/65 R15 88H')
        ->and($logan->model->fullName())->toBe('Dacia Logan')
        ->and($logan->source->external_id)->toBe('DAC-LOG-BROCH-MA')
        ->and($logan->status)->toBe(CompatibilityStatus::ToCheck);

    $picanto = OilFitment::query()->where('external_id', 'OIL-KIA-PIC-10MPI')->firstOrFail();
    expect($picanto->viscosity)->toBe('5W-30')->and($picanto->acea)->toBe('A5/B5')->and($picanto->capacity_ml)->toBe(2900)
        ->and($picanto->status)->toBe(CompatibilityStatus::ToCheck);

    expect(OilFitment::query()->where('external_id', 'OIL-HYU-GI10-12MPI')->value('status'))->toBe(CompatibilityStatus::Unverified);
});

it('never imports anything as verified (D-24)', function () {
    importWorkbook();

    expect(TyreFitment::query()->verified()->count())->toBe(0)
        ->and(OilFitment::query()->verified()->count())->toBe(0);
});

it('is idempotent and never overwrites a compatibility verified in the app', function () {
    importWorkbook();
    $owner = owner();
    $fitment = TyreFitment::query()->where('external_id', 'TIR-DAC-LOG-ESS')->firstOrFail();
    app(FitmentStatusAction::class)->execute($fitment, CompatibilityStatus::Verified, $owner, 'Étiquette de portière contrôlée');

    $second = importWorkbook();

    expect(TyreFitment::query()->count())->toBe(13)
        ->and(VehicleModel::query()->count())->toBe(263)
        ->and($second['counts']['VEHICLE_TIRE_FITMENTS']['created'])->toBe(0)
        ->and($fitment->fresh()->status)->toBe(CompatibilityStatus::Verified);
});

it('requires the permission and a source to verify, and journals it', function () {
    importWorkbook();
    $fitment = TyreFitment::query()->where('external_id', 'TIR-DAC-SST-REF')->firstOrFail();

    expect(fn () => app(FitmentStatusAction::class)->execute($fitment, CompatibilityStatus::Verified, userWithRole(RoleName::Seller, 'M01')))
        ->toThrow(BusinessRuleException::class);

    $fitment->update(['source_id' => null]);
    expect(fn () => app(FitmentStatusAction::class)->execute($fitment->fresh(), CompatibilityStatus::Verified, owner()))
        ->toThrow(BusinessRuleException::class, 'source');

    $fitment->update(['source_id' => CompatibilitySource::query()->first()->id]);
    $owner = owner();
    app(FitmentStatusAction::class)->execute($fitment->fresh(), CompatibilityStatus::Verified, $owner);

    expect($fitment->fresh()->verified_by)->toBe($owner->id)
        ->and(Activity::query()->where('subject_type', (new TyreFitment)->getMorphClass())->where('subject_id', $fitment->id)->where('event', 'updated')->exists())->toBeTrue();
});

it('shows a seller only verified compatibilities, even by direct URL (§7.3)', function () {
    importWorkbook();
    $verified = TyreFitment::query()->where('external_id', 'TIR-DAC-LOG-ESS')->firstOrFail();
    $hidden = TyreFitment::query()->where('external_id', 'TIR-DAC-LOG-EXP')->firstOrFail();
    app(FitmentStatusAction::class)->execute($verified, CompatibilityStatus::Verified, owner());

    $this->actingAs(userWithRole(RoleName::Seller, 'M01'));
    Livewire::test(ListTyreFitments::class)
        ->assertCanSeeTableRecords([$verified])
        ->assertCanNotSeeTableRecords([$hidden]);
    $this->get("/admin/tyre-fitments/{$hidden->id}")->assertNotFound();
    $this->get('/admin/tyre-fitments/create')->assertForbidden();
});

it('lets the owner verify from the screen', function () {
    importWorkbook();
    $fitment = TyreFitment::query()->where('external_id', 'TIR-REN-CLI-16')->firstOrFail();

    $this->actingAs(owner());
    Livewire::test(ViewTyreFitment::class, ['record' => $fitment->getRouteKey()])
        ->callAction('verify', ['comment' => 'Contrôlé sur véhicule'])
        ->assertHasNoActionErrors();

    expect($fitment->fresh()->status)->toBe(CompatibilityStatus::Verified)
        ->and($fitment->fresh()->notes)->toContain('Contrôlé sur véhicule');
});

it('sends an edited verified compatibility back to "to check"', function () {
    importWorkbook();
    $owner = owner();
    $fitment = TyreFitment::query()->where('external_id', 'TIR-HYU-ACC-REF')->firstOrFail();
    app(FitmentStatusAction::class)->execute($fitment, CompatibilityStatus::Verified, $owner);

    $this->actingAs($owner);
    Livewire::test(EditTyreFitment::class, ['record' => $fitment->getRouteKey()])
        ->fillForm(['load_index' => '88', 'speed_index' => 'H'])
        ->call('save')
        ->assertHasNoFormErrors();

    expect($fitment->fresh()->status)->toBe(CompatibilityStatus::ToCheck)
        ->and($fitment->fresh()->verified_by)->toBeNull();
});

it('finds the products matching a vehicle on the lookup page', function () {
    importWorkbook();
    $owner = owner();
    $fitment = TyreFitment::query()->where('external_id', 'TIR-DAC-SST-REF')->firstOrFail();
    app(FitmentStatusAction::class)->execute($fitment, CompatibilityStatus::Verified, $owner);
    $tyre = Product::factory()->tyre(205, 60, '16')->create(['name' => 'Pneu Stepway test']);
    app(StockService::class)->receive($tyre, site('M01'), 4, 50000);
    Product::factory()->tyre(195, 65, '15')->create(['name' => 'Autre dimension']);

    expect($fitment->matchingProducts()->pluck('id')->all())->toBe([$tyre->id]);

    $this->actingAs(userWithRole(RoleName::Seller, 'M01'));
    Livewire::test(VehicleLookup::class)
        ->set('modelId', $fitment->model_id)
        ->assertSee('205/60 R16')
        ->assertSee('Pneu Stepway test')
        ->assertSee('M01 4')
        ->assertDontSee('Autre dimension')
        ->assertDontSee(__('vehicles.lookup.unverified_title'));
});

it('renders the vehicle screens for the owner and denies the technician', function () {
    importWorkbook();
    $urls = ['/admin/vehicle-lookup', '/admin/vehicle-makes', '/admin/vehicle-models', '/admin/vehicle-generations',
        '/admin/vehicle-engines', '/admin/compatibility-sources', '/admin/tyre-fitments', '/admin/tyre-fitments/create',
        '/admin/oil-fitments', '/admin/oil-fitments/create',
        '/admin/tyre-fitments/'.TyreFitment::query()->value('id'), '/admin/oil-fitments/'.OilFitment::query()->value('id')];

    $this->actingAs(owner());
    foreach ($urls as $url) {
        expect($this->get($url)->status())->toBe(200, $url);
    }

    $this->actingAs(userWithRole(RoleName::Technician, 'M01'));
    foreach (['/admin/vehicle-lookup', '/admin/tyre-fitments', '/admin/vehicle-models'] as $url) {
        $this->get($url)->assertForbidden();
    }
});
