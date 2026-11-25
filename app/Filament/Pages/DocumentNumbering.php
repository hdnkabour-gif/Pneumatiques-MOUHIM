<?php

namespace App\Filament\Pages;

use App\Enums\DocumentType;
use App\Enums\Permission;
use App\Exceptions\BusinessRuleException;
use App\Filament\Support\PageHub;
use App\Services\InvoiceNumberingApproval;
use App\Services\SequenceService;
use App\Services\SettingsService;
use BackedEnum;
use Filament\Actions\Action;
use Filament\Forms\Components\TextInput;
use Filament\Notifications\Notification;
use Filament\Pages\Page;
use Filament\Schemas\Components\Actions;
use Filament\Schemas\Components\EmbeddedSchema;
use Filament\Schemas\Components\Form;
use Filament\Schemas\Components\Grid;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Components\Text;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Illuminate\Support\Facades\DB;

/**
 * D-49: prefix of each document and next number of the year, to continue
 * the company's paper numbering. Going back is refused (no duplicate).
 *
 * @property-read Schema $form
 */
class DocumentNumbering extends Page
{
    public static function shouldRegisterNavigation(): bool
    {
        return PageHub::inMenu(static::class) && parent::shouldRegisterNavigation();
    }

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedHashtag;

    protected static ?int $navigationSort = 91;

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
        return __('settings.numbering.title');
    }

    public function getTitle(): string
    {
        return __('settings.numbering.title');
    }

    /** Documents numbered so far (later phases add their own). */
    public static function types(): array
    {
        return DocumentType::cases();
    }

    public function mount(SettingsService $settings): void
    {
        $data = [];
        foreach (self::types() as $type) {
            $data[$type->value] = ['prefix' => $settings->get($type->prefixSetting()) ?? $type->defaultPrefix(), 'next' => null];
        }
        $data['number_pattern'] = $settings->get('documents.number_pattern');
        $data['number_padding'] = $settings->get('documents.number_padding');
        $this->form->fill($data);
    }

    public function form(Schema $schema): Schema
    {
        $year = (int) now()->format('Y');
        $sequences = app(SequenceService::class);

        $rows = array_map(fn (DocumentType $type) => Grid::make(['default' => 2, 'md' => 4])->schema([
            Text::make($type->label())->weight('bold')->columnSpan(['default' => 2, 'md' => 1]),
            TextInput::make($type->value.'.prefix')->label(__('settings.numbering.prefix'))
                ->maxLength(10)->regex('/^[A-Za-z0-9]*$/')->live(onBlur: true),
            TextInput::make($type->value.'.next')->label(__('settings.numbering.next_number'))
                ->integer()->minValue($sequences->lastNumber($type, $year) + 1)
                ->placeholder((string) ($sequences->lastNumber($type, $year) + 1))->live(onBlur: true),
            Text::make(fn (Get $get) => __('settings.numbering.preview').' : '.$this->preview($type, $year, $get($type->value.'.prefix'), $get($type->value.'.next'), $get('number_pattern'), $get('number_padding')))
                ->color('gray'),
        ]), self::types());

        $formatFields = [
            TextInput::make('number_pattern')
                ->label(__('settings.fields.document_number_pattern'))
                ->helperText(__('settings.fields.document_number_pattern_help'))
                ->required()->maxLength(60)->live(onBlur: true),
            TextInput::make('number_padding')
                ->label(__('settings.fields.document_number_padding'))
                ->required()->integer()->minValue(1)->maxValue(10)->live(onBlur: true),
        ];

        return $schema->statePath('data')->components([
            Section::make(__('settings.numbering.year', ['year' => $year]))
                ->description(__('settings.numbering.help'))
                ->schema([...$formatFields, ...$rows]),
        ]);
    }

    /**
     * #20: the owner approves the exact number of the first invoice. Visible until the invoice
     * series exists; the approval goes stale if the format or the next number changes later.
     */
    protected function getHeaderActions(): array
    {
        $approval = app(InvoiceNumberingApproval::class);

        return [
            Action::make('approveInvoiceNumbering')
                ->label(__('settings.numbering.approve'))
                ->color(fn () => $approval->problem() === null ? 'gray' : 'warning')
                ->visible(fn () => ! $approval->seriesEstablished())
                ->requiresConfirmation()
                // The number shown is frozen into the form when the window opens and sent back on
                // confirmation: approve() refuses it if the numbering changed meanwhile (#32 review).
                // The window DISPLAYS exactly this captured value (read-only field) and submits it; the
                // description never recomputes a number, so what is approved and audited is what was seen.
                ->fillForm(fn () => ['number' => $approval->nextInvoiceNumber()])
                ->schema([TextInput::make('number')->label(__('settings.numbering.first_invoice'))->readOnly()])
                ->modalDescription(__('settings.numbering.approve_confirm'))
                ->action(function (array $data) use ($approval) {
                    abort_unless(static::canAccess(), 403);
                    try {
                        $number = $approval->approve(auth()->user(), (string) ($data['number'] ?? ''));
                    } catch (BusinessRuleException $e) {
                        Notification::make()->danger()->title($e->getMessage())->send();

                        return;
                    }
                    Notification::make()->success()->title(__('settings.numbering.approved', ['number' => $number]))->send();
                }),
        ];
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

    public function save(SettingsService $settings, SequenceService $sequences): void
    {
        abort_unless(static::canAccess(), 403);

        $d = $this->form->getState();
        $year = (int) now()->format('Y');

        try {
            DB::transaction(function () use ($d, $settings, $sequences, $year) {
                $pattern = trim((string) ($d['number_pattern'] ?? ''));
                $padding = (int) ($d['number_padding'] ?? 0);
                if (strlen($pattern) > 60
                    || ! preg_match('/^(?:\\{PREFIX\\}|\\{NUMBER\\}|\\{YEAR\\}|\\{YY\\}|[ ._\\/-])+$/D', $pattern)
                    || ! str_contains($pattern, '{PREFIX}')
                    || ! str_contains($pattern, '{NUMBER}')
                    || (! str_contains($pattern, '{YY}') && ! str_contains($pattern, '{YEAR}'))
                    || $padding < 1 || $padding > 10) {
                    throw new BusinessRuleException(__('settings.fields.document_number_pattern_invalid'));
                }

                $prefixes = [];
                foreach (self::types() as $type) {
                    $prefixes[$type->prefixSetting()] = strtoupper(trim((string) $d[$type->value]['prefix']));
                    if (filled($d[$type->value]['next'] ?? null)) {
                        $before = $sequences->lastNumber($type, $year);
                        $sequences->setNextNumber($type, $year, (int) $d[$type->value]['next']);
                        activity('settings')->causedBy(auth()->user())->event('numbering_changed')
                            ->withProperties(['type' => $type->value, 'year' => $year, 'last_before' => $before, 'next' => (int) $d[$type->value]['next']])
                            ->log('numbering_changed');
                    }
                }
                if (count(array_unique($prefixes)) !== count($prefixes)) {
                    throw new BusinessRuleException(__('settings.numbering.duplicate_prefix'));
                }
                $settings->setMany($prefixes + [
                    'documents.number_pattern' => $pattern,
                    'documents.number_padding' => $padding,
                ], auth()->user());
            });
        } catch (BusinessRuleException $e) {
            Notification::make()->danger()->title($e->getMessage())->send();

            return;
        }

        $this->mount($settings);
        Notification::make()->success()->title(__('settings.saved'))->send();
    }

    private function preview(DocumentType $type, int $year, ?string $prefix, mixed $next, ?string $pattern, mixed $padding): string
    {
        $settings = app(SettingsService::class);
        $number = filled($next) ? (int) $next : app(SequenceService::class)->lastNumber($type, $year) + 1;

        return strtr((string) ($pattern ?: $settings->get('documents.number_pattern')), [
            '{PREFIX}' => strtoupper((string) $prefix),
            '{YEAR}' => (string) $year,
            '{YY}' => substr((string) $year, -2),
            '{NUMBER}' => str_pad((string) $number, (int) ($padding ?: $settings->get('documents.number_padding')), '0', STR_PAD_LEFT),
        ]);
    }
}
