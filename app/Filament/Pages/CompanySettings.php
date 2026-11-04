<?php

namespace App\Filament\Pages;

use App\Enums\Permission;
use App\Services\SettingsService;
use App\Support\Decimal;
use BackedEnum;
use Filament\Actions\Action;
use Filament\Forms\Components\FileUpload;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Notifications\Notification;
use Filament\Pages\Page;
use Filament\Schemas\Components\Actions;
use Filament\Schemas\Components\EmbeddedSchema;
use Filament\Schemas\Components\Form;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;

/**
 * Company identity and fiscal parameters (D-19, D-30). Rates and amounts
 * are edited as decimals and stored as integers (basis points / cents).
 *
 * @property-read Schema $form
 */
class CompanySettings extends Page
{
    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedCog6Tooth;

    protected static ?int $navigationSort = 90;

    /** @var array<string, mixed>|null */
    public ?array $data = [];

    public static function canAccess(): bool
    {
        return auth()->user()?->can(Permission::SettingsManage->value) ?? false;
    }

    public static function getNavigationGroup(): ?string
    {
        return __('app.navigation.settings');
    }

    public static function getNavigationLabel(): string
    {
        return __('settings.title');
    }

    public function getTitle(): string
    {
        return __('settings.title');
    }

    public function mount(SettingsService $settings): void
    {
        $s = $settings->all();

        $this->form->fill([
            'company_name' => $s['company.name'],
            'company_address' => $s['company.address'],
            'company_city' => $s['company.city'],
            'company_phone' => $s['company.phone'],
            'company_email' => $s['company.email'],
            'company_logo' => $s['company.logo'],
            'ice' => $s['legal.ice'],
            'if' => $s['legal.if'],
            'rc' => $s['legal.rc'],
            'patente' => $s['legal.patente'],
            'cnss' => $s['legal.cnss'],
            'bank_name' => $s['bank.name'],
            'rib' => $s['bank.rib'],
            'vat_rate' => Decimal::basisPointsToPercent((int) $s['tax.vat_rate_bp']),
            'stamp_duty_enabled' => (bool) $s['tax.stamp_duty_enabled'],
            'stamp_duty_rate' => Decimal::basisPointsToPercent((int) $s['tax.stamp_duty_rate_bp']),
            'stamp_duty_threshold' => Decimal::fromCents((int) $s['tax.stamp_duty_threshold_cents']),
            'used_tyre_vat_enabled' => (bool) $s['tax.used_tyre_vat_enabled'],
            'trade_in_vat_enabled' => (bool) $s['tax.trade_in_vat_enabled'],
            'legal_mentions' => $s['documents.legal_mentions'],
            'document_number_pattern' => $s['documents.number_pattern'],
            'document_number_padding' => (int) $s['documents.number_padding'],
        ]);
    }

    public function form(Schema $schema): Schema
    {
        $decimal = ['regex:/^\d+([.,]\d{1,2})?$/'];

        return $schema
            ->statePath('data')
            ->components([
                Section::make(__('settings.sections.company'))
                    ->columns(2)
                    ->schema([
                        TextInput::make('company_name')->label(__('settings.fields.company_name'))->required()->maxLength(255),
                        TextInput::make('company_phone')->label(__('settings.fields.company_phone'))->tel()->maxLength(30),
                        TextInput::make('company_address')->label(__('settings.fields.company_address'))->maxLength(255),
                        TextInput::make('company_city')->label(__('settings.fields.company_city'))->maxLength(100),
                        TextInput::make('company_email')->label(__('settings.fields.company_email'))->email()->maxLength(255),
                        FileUpload::make('company_logo')->label(__('settings.fields.company_logo'))
                            ->image()->disk('public')->directory('company')->maxSize(2048),
                    ]),
                Section::make(__('settings.sections.legal'))
                    ->columns(3)
                    ->collapsible()
                    ->schema([
                        TextInput::make('ice')->label(__('settings.fields.ice'))->maxLength(20),
                        TextInput::make('if')->label(__('settings.fields.if'))->maxLength(20),
                        TextInput::make('rc')->label(__('settings.fields.rc'))->maxLength(30),
                        TextInput::make('patente')->label(__('settings.fields.patente'))->maxLength(30),
                        TextInput::make('cnss')->label(__('settings.fields.cnss'))->maxLength(30),
                    ]),
                Section::make(__('settings.sections.bank'))
                    ->columns(2)
                    ->collapsible()
                    ->schema([
                        TextInput::make('bank_name')->label(__('settings.fields.bank_name'))->maxLength(100),
                        TextInput::make('rib')->label(__('settings.fields.rib'))->maxLength(34),
                    ]),
                Section::make(__('settings.sections.tax'))
                    ->description(__('settings.sections.tax_help'))
                    ->columns(3)
                    ->collapsible()
                    ->schema([
                        TextInput::make('vat_rate')->label(__('settings.fields.vat_rate'))->required()->rules($decimal)->suffix('%'),
                        Toggle::make('used_tyre_vat_enabled')->label(__('settings.fields.used_tyre_vat_enabled')),
                        Toggle::make('trade_in_vat_enabled')->label(__('settings.fields.trade_in_vat_enabled')),
                        Toggle::make('stamp_duty_enabled')->label(__('settings.fields.stamp_duty_enabled'))->live()->columnSpanFull(),
                        TextInput::make('stamp_duty_rate')->label(__('settings.fields.stamp_duty_rate'))->rules($decimal)->suffix('%')
                            ->visible(fn (Get $get) => (bool) $get('stamp_duty_enabled')),
                        TextInput::make('stamp_duty_threshold')->label(__('settings.fields.stamp_duty_threshold'))->rules($decimal)->suffix('MAD')
                            ->visible(fn (Get $get) => (bool) $get('stamp_duty_enabled')),
                        TextInput::make('document_number_pattern')->label(__('settings.fields.document_number_pattern'))
                            ->helperText(__('settings.fields.document_number_pattern_help'))->required()->maxLength(50),
                        TextInput::make('document_number_padding')->label(__('settings.fields.document_number_padding'))
                            ->integer()->minValue(3)->maxValue(10)->required(),
                        Textarea::make('legal_mentions')->label(__('settings.fields.legal_mentions'))->rows(3)->columnSpanFull(),
                    ]),
            ]);
    }

    public function content(Schema $schema): Schema
    {
        return $schema->components([
            Form::make([EmbeddedSchema::make('form')])
                ->id('form')
                ->livewireSubmitHandler('save')
                ->footer([
                    Actions::make([
                        Action::make('save')->label(__('filament-panels::resources/pages/edit-record.form.actions.save.label'))->submit('save'),
                    ])->key('form-actions'),
                ]),
        ]);
    }

    public function save(SettingsService $settings): void
    {
        abort_unless(static::canAccess(), 403);

        $d = $this->form->getState();
        $current = $settings->all();

        $settings->setMany([
            'company.name' => $d['company_name'],
            'company.address' => $d['company_address'] ?? null,
            'company.city' => $d['company_city'] ?? null,
            'company.phone' => $d['company_phone'] ?? null,
            'company.email' => $d['company_email'] ?? null,
            'company.logo' => $d['company_logo'] ?? null,
            'legal.ice' => $d['ice'] ?? null,
            'legal.if' => $d['if'] ?? null,
            'legal.rc' => $d['rc'] ?? null,
            'legal.patente' => $d['patente'] ?? null,
            'legal.cnss' => $d['cnss'] ?? null,
            'bank.name' => $d['bank_name'] ?? null,
            'bank.rib' => $d['rib'] ?? null,
            'tax.vat_rate_bp' => Decimal::percentToBasisPoints($d['vat_rate']),
            'tax.stamp_duty_enabled' => (bool) $d['stamp_duty_enabled'],
            'tax.stamp_duty_rate_bp' => isset($d['stamp_duty_rate']) ? Decimal::percentToBasisPoints($d['stamp_duty_rate']) : $current['tax.stamp_duty_rate_bp'],
            'tax.stamp_duty_threshold_cents' => isset($d['stamp_duty_threshold']) ? Decimal::toCents($d['stamp_duty_threshold']) : $current['tax.stamp_duty_threshold_cents'],
            'tax.used_tyre_vat_enabled' => (bool) $d['used_tyre_vat_enabled'],
            'tax.trade_in_vat_enabled' => (bool) $d['trade_in_vat_enabled'],
            'documents.legal_mentions' => $d['legal_mentions'] ?? null,
            'documents.number_pattern' => $d['document_number_pattern'],
            'documents.number_padding' => (int) $d['document_number_padding'],
        ], auth()->user());

        Notification::make()->success()->title(__('settings.saved'))->send();
    }
}
