<?php

use App\Enums\RoleName;
use App\Filament\Pages\CompanySettings;
use App\Models\Activity;
use App\Services\SettingsService;
use Livewire\Livewire;

it('defaults to a single 20 % VAT rate stored in basis points', function () {
    expect(app(SettingsService::class)->vatRateBasisPoints())->toBe(2000);
});

it('keeps fiscal rules configurable and disabled until validated (D-30)', function () {
    $settings = app(SettingsService::class);

    expect($settings->get('tax.stamp_duty_enabled'))->toBeFalse()
        ->and($settings->get('documents.number_pattern'))->toBe('{PREFIX}-{YEAR}-{NUMBER}');
});

it('rejects unknown setting keys', function () {
    app(SettingsService::class)->set('tax.typo', 1);
})->throws(InvalidArgumentException::class);

it('lets the owner edit company and fiscal settings', function () {
    $owner = owner();
    $this->actingAs($owner);

    Livewire::test(CompanySettings::class)
        ->fillForm([
            'company_name' => 'PNEU MOUHIM SARL',
            'ice' => '001234567000089',
            'vat_rate' => '20',
            'stamp_duty_enabled' => true,
            'stamp_duty_rate' => '0,25',
            'stamp_duty_threshold' => '100,50',
        ])
        ->call('save')
        ->assertHasNoFormErrors()
        ->assertNotified();

    $settings = app(SettingsService::class);
    expect($settings->get('company.name'))->toBe('PNEU MOUHIM SARL')
        ->and($settings->get('legal.ice'))->toBe('001234567000089')
        ->and($settings->get('tax.stamp_duty_enabled'))->toBeTrue()
        ->and($settings->get('tax.stamp_duty_rate_bp'))->toBe(25)
        ->and($settings->get('tax.stamp_duty_threshold_cents'))->toBe(10050);

    $log = Activity::query()->where('event', 'settings_updated')->latest('id')->first();
    expect($log->causer_id)->toBe($owner->id)
        ->and($log->properties['attributes']['company.name'])->toBe('PNEU MOUHIM SARL');
});

it('rejects an invalid VAT rate', function () {
    $this->actingAs(owner());

    Livewire::test(CompanySettings::class)
        ->fillForm(['vat_rate' => 'vingt'])
        ->call('save')
        ->assertHasFormErrors(['vat_rate']);
});

it('forbids non-owners from saving settings, even via Livewire', function () {
    $this->actingAs(userWithRole(RoleName::SiteManager, 'M01'));

    Livewire::test(CompanySettings::class)->assertForbidden();
});
