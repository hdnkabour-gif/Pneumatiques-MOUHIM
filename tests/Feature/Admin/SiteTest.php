<?php

use App\Enums\RoleName;
use App\Enums\SiteType;
use App\Filament\Resources\Sites\Pages\CreateSite;
use App\Filament\Resources\Sites\Pages\EditSite;
use App\Filament\Resources\Sites\Pages\ListSites;
use App\Models\Activity;
use App\Models\Site;
use Livewire\Livewire;

it('seeds the three company sites', function () {
    expect(Site::query()->orderBy('code')->pluck('code')->all())->toBe(['DEP', 'M01', 'M02'])
        ->and(site('DEP')->type)->toBe(SiteType::Depot)
        ->and(site('M01')->has_workshop)->toBeTrue();
});

it('shows the owner every site', function () {
    $this->actingAs(owner());

    Livewire::test(ListSites::class)->assertCanSeeTableRecords(Site::all());
});

it('shows a M01 seller only the M01 site', function () {
    $this->actingAs(userWithRole(RoleName::Seller, 'M01'));

    Livewire::test(ListSites::class)
        ->assertCanSeeTableRecords([site('M01')])
        ->assertCanNotSeeTableRecords([site('M02'), site('DEP')]);
});

it('refuses a M01 user opening the M02 site by URL', function () {
    $this->actingAs(userWithRole(RoleName::SiteManager, 'M01'))
        ->get('/admin/sites/'.site('M02')->id.'/edit')
        ->assertNotFound();
});

it('lets the owner create a site, normalising the code', function () {
    $this->actingAs(owner());

    Livewire::test(CreateSite::class)
        ->fillForm(['code' => 'm03', 'name' => 'Magasin 03', 'type' => SiteType::Store->value])
        ->call('create')
        ->assertHasNoFormErrors();

    expect(Site::query()->where('code', 'M03')->exists())->toBeTrue();
});

it('rejects a duplicate site code', function () {
    $this->actingAs(owner());

    Livewire::test(CreateSite::class)
        ->fillForm(['code' => 'M01', 'name' => 'Doublon', 'type' => SiteType::Store->value])
        ->call('create')
        ->assertHasFormErrors(['code' => 'unique']);
});

it('logs site changes with before and after values', function () {
    $owner = owner();
    $this->actingAs($owner);

    Livewire::test(EditSite::class, ['record' => site('M01')->getRouteKey()])
        ->fillForm(['name' => 'Magasin Centre-ville'])
        ->call('save')
        ->assertHasNoFormErrors();

    $log = Activity::query()->where('subject_type', (new Site)->getMorphClass())
        ->where('subject_id', site('M01')->id)->where('event', 'updated')->latest('id')->first();

    expect($log)->not->toBeNull()
        ->and($log->causer_id)->toBe($owner->id)
        ->and($log->site_id)->toBe(site('M01')->id)
        ->and($log->attribute_changes['old']['name'])->toBe('Magasin 01')
        ->and($log->attribute_changes['attributes']['name'])->toBe('Magasin Centre-ville');
});

it('never deletes a site', function () {
    expect(owner()->can('delete', site('M02')))->toBeFalse();
});
