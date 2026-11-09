<?php

use App\Actions\Equipment\EquipmentActions;
use App\Enums\RoleName;
use App\Exceptions\BusinessRuleException;
use App\Filament\Resources\Equipment\EquipmentResource;
use App\Filament\Resources\Equipment\Pages\CreateEquipment;
use App\Filament\Resources\Equipment\Pages\ListEquipment;
use App\Models\Equipment;
use App\Services\Reports\DailyFigures;
use Livewire\Livewire;

it('registers equipment, plans maintenance, records breakdown and repair with costs (§15)', function () {
    $manager = userWithRole(RoleName::SiteManager, 'M01');
    $seller = userWithRole(RoleName::Seller, 'M01');
    $actions = app(EquipmentActions::class);

    expect(fn () => $actions->create(['name' => 'Équilibreuse', 'category' => 'balancer', 'site_id' => site('M01')->id], $seller))->toThrow(BusinessRuleException::class);
    $eq = $actions->create(['name' => 'Équilibreuse', 'category' => 'balancer', 'site_id' => site('M01')->id, 'maintenance_every_days' => 90], $manager);
    expect($eq->code)->toBe('EQ-000001')->and($eq->next_maintenance_on->toDateString())->toBe(today()->addDays(90)->toDateString());

    $actions->reportBreakdown($eq, 'Ne tourne plus', $seller);
    expect($eq->fresh()->status)->toBe('broken')
        ->and($manager->notifications()->count())->toBe(1)
        ->and(app(DailyFigures::class)->alerts(now()->toImmutable()))->toContain(__('equipment.alert_due', ['count' => 1]));

    $actions->service($eq->fresh(), 'repair', 'Courroie changée', 35000, 'Technicien SAV', $manager);
    expect($eq->fresh()->status)->toBe('in_service')->and($eq->fresh()->totalCostCents())->toBe(35000);
});

it('manages equipment from the screen, sellers only report', function () {
    $this->actingAs(userWithRole(RoleName::SiteManager, 'M01'));
    Livewire::test(CreateEquipment::class)
        ->fillForm(['name' => 'Pont élévateur 2 colonnes', 'category' => 'lift', 'site_id' => site('M01')->id, 'maintenance_every_days' => 180])
        ->call('create')->assertHasNoFormErrors();
    $eq = Equipment::query()->firstOrFail();

    $this->actingAs(userWithRole(RoleName::Seller, 'M01'));
    Livewire::test(ListEquipment::class)->assertCanSeeTableRecords([$eq])
        ->assertTableActionHidden('service', $eq)
        ->callTableAction('breakdown', $eq, ['description' => 'Fuite hydraulique']);
    expect($eq->fresh()->status)->toBe('broken');
    $this->get(EquipmentResource::getUrl('create'))->assertForbidden();
});

it('hides the create button from those who cannot create', function () {
    $this->actingAs(userWithRole(RoleName::Seller, 'M01'));
    Livewire::test(ListEquipment::class)->assertActionHidden('create');
});
