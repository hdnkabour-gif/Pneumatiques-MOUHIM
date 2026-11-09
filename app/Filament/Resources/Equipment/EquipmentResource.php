<?php

namespace App\Filament\Resources\Equipment;

use App\Actions\Equipment\EquipmentActions;
use App\Enums\Permission;
use App\Exceptions\BusinessRuleException;
use App\Filament\Resources\Equipment\Pages\CreateEquipment;
use App\Filament\Resources\Equipment\Pages\EditEquipment;
use App\Filament\Resources\Equipment\Pages\ListEquipment;
use App\Filament\Resources\Equipment\Pages\ViewEquipment;
use App\Filament\Support\Fields;
use App\Models\Equipment;
use App\Models\Site;
use App\Support\Money;
use BackedEnum;
use Filament\Actions\Action;
use Filament\Actions\EditAction;
use Filament\Actions\ViewAction;
use Filament\Forms\Components\DatePicker;
use Filament\Forms\Components\FileUpload;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Infolists\Components\RepeatableEntry;
use Filament\Infolists\Components\TextEntry;
use Filament\Notifications\Notification;
use Filament\Resources\Resource;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;

/** Workshop equipment and maintenance (§15). */
class EquipmentResource extends Resource
{
    protected static ?string $model = Equipment::class;

    protected static bool $hasTitleCaseModelLabel = false;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedCog8Tooth;

    protected static ?int $navigationSort = 40;

    protected static ?string $slug = 'equipment';

    public static function canViewAny(): bool
    {
        return auth()->user()?->canAny([Permission::EquipmentManage->value, Permission::EquipmentReport->value]) ?? false;
    }

    public static function canCreate(): bool
    {
        return auth()->user()?->can(Permission::EquipmentManage->value) ?? false;
    }

    public static function canEdit($record): bool
    {
        return self::canCreate() && auth()->user()->canAccessSite($record->site_id);
    }

    public static function canDelete($record): bool
    {
        return false;
    }

    public static function getModelLabel(): string
    {
        return __('equipment.label');
    }

    public static function getPluralModelLabel(): string
    {
        return __('equipment.plural');
    }

    public static function getNavigationGroup(): ?string
    {
        return __('app.navigation.sales');
    }

    public static function getEloquentQuery(): Builder
    {
        return parent::getEloquentQuery()->visibleTo(auth()->user())->with('site');
    }

    public static function form(Schema $schema): Schema
    {
        return $schema->components([
            Section::make()->columns(2)->schema([
                TextInput::make('name')->label(__('equipment.fields.name'))->required()->maxLength(255),
                Select::make('category')->label(__('equipment.fields.category'))->required()
                    ->options(collect(Equipment::CATEGORIES)->mapWithKeys(fn ($c) => [$c => __('equipment.categories.'.$c)])->all()),
                Select::make('site_id')->label(__('equipment.fields.site'))->required()
                    ->options(fn () => Site::query()->visibleTo(auth()->user())->where('is_active', true)->orderBy('code')->pluck('name', 'id')),
                TextInput::make('maintenance_every_days')->label(__('equipment.fields.every_days'))->integer()->minValue(1)
                    ->helperText(__('equipment.every_help')),
            ]),
            Section::make(__('app.more_details'))->collapsed()->columns(2)->schema([
                TextInput::make('brand')->label(__('equipment.fields.brand')),
                TextInput::make('model')->label(__('equipment.fields.model')),
                TextInput::make('serial_number')->label(__('equipment.fields.serial')),
                DatePicker::make('purchased_at')->label(__('equipment.fields.purchased_at')),
                Fields::money('purchase_cost_cents', __('equipment.fields.cost'))->visible(fn () => Fields::canSeeCosts()),
                DatePicker::make('warranty_until')->label(__('equipment.fields.warranty_until')),
                DatePicker::make('next_maintenance_on')->label(__('equipment.fields.next')),
                FileUpload::make('photo')->label(__('equipment.fields.photo'))->image()->disk('public')->directory('equipment')->maxSize(5120),
                Textarea::make('notes')->label(__('equipment.fields.notes'))->columnSpanFull(),
            ]),
        ]);
    }

    public static function infolist(Schema $schema): Schema
    {
        return $schema->components([
            Section::make()->columns(4)->schema([
                TextEntry::make('code')->label(__('equipment.fields.code'))->weight('bold'),
                TextEntry::make('name')->label(__('equipment.fields.name')),
                TextEntry::make('site.name')->label(__('equipment.fields.site')),
                TextEntry::make('status')->label(__('equipment.fields.status'))->badge()->formatStateUsing(fn ($state) => __('equipment.status.'.$state)),
                TextEntry::make('next_maintenance_on')->label(__('equipment.fields.next'))->date('d/m/Y')->placeholder('—'),
                TextEntry::make('warranty_until')->label(__('equipment.fields.warranty_until'))->date('d/m/Y')->placeholder('—'),
                TextEntry::make('serial_number')->label(__('equipment.fields.serial'))->placeholder('—'),
                TextEntry::make('total')->label(__('equipment.fields.total_cost'))->visible(fn () => Fields::canSeeCosts())
                    ->state(fn (Equipment $record) => Money::format($record->totalCostCents())),
            ]),
            Section::make(__('equipment.history'))->schema([
                RepeatableEntry::make('events')->hiddenLabel()->columns(4)->placeholder('—')->schema([
                    TextEntry::make('occurred_on')->label(__('equipment.fields.date'))->date('d/m/Y'),
                    TextEntry::make('type')->label(__('equipment.fields.type'))->badge()->formatStateUsing(fn ($state) => __('equipment.events.'.$state)),
                    TextEntry::make('description')->label(__('equipment.fields.description')),
                    TextEntry::make('cost_cents')->label(__('equipment.fields.cost'))->formatStateUsing(fn ($state) => Money::format((int) $state))
                        ->visible(fn () => Fields::canSeeCosts()),
                ]),
            ]),
        ]);
    }

    public static function table(Table $table): Table
    {
        return $table->defaultSort('next_maintenance_on')
            ->columns([
                TextColumn::make('name')->label(__('equipment.fields.name'))->weight('bold')->searchable()
                    ->description(fn (Equipment $record) => __('equipment.categories.'.$record->category)),
                TextColumn::make('site.code')->label(__('equipment.fields.site'))->badge(),
                TextColumn::make('status')->label(__('equipment.fields.status'))->badge()->formatStateUsing(fn ($state) => __('equipment.status.'.$state))
                    ->color(fn ($state) => match ($state) {
                        'broken' => 'danger', 'retired' => 'gray', default => 'success'
                    }),
                TextColumn::make('next_maintenance_on')->label(__('equipment.fields.next'))->date('d/m/Y')->placeholder('—')
                    ->color(fn (Equipment $record) => $record->next_maintenance_on?->lte(today()->addDays(7)) ? 'danger' : null),
            ])
            ->filters([
                SelectFilter::make('site_id')->label(__('equipment.fields.site'))->options(fn () => Site::query()->visibleTo(auth()->user())->orderBy('code')->pluck('name', 'id')),
                SelectFilter::make('status')->label(__('equipment.fields.status'))->options(__('equipment.status')),
            ])
            ->recordActions([ViewAction::make(), ...self::eventActions(), EditAction::make()]);
    }

    /** Report a breakdown (anyone on the site), record a repair or a maintenance (managers). */
    public static function eventActions(): array
    {
        $run = function (callable $cb) {
            try {
                $cb();
                Notification::make()->success()->title(__('cash.saved'))->send();
            } catch (BusinessRuleException $e) {
                Notification::make()->danger()->title($e->getMessage())->send();
            }
        };

        return [
            Action::make('breakdown')->label(__('equipment.actions.breakdown'))->icon(Heroicon::OutlinedExclamationTriangle)->color('danger')
                ->visible(fn (Equipment $record) => auth()->user()->can(Permission::EquipmentReport->value) && $record->status !== 'broken')
                ->schema([Textarea::make('description')->label(__('equipment.fields.what'))->required()])
                ->action(fn (Equipment $record, array $data) => $run(fn () => app(EquipmentActions::class)->reportBreakdown($record, $data['description'], auth()->user()))),
            Action::make('service')->label(__('equipment.actions.service'))->icon(Heroicon::OutlinedWrench)->color('success')
                ->visible(fn () => auth()->user()->can(Permission::EquipmentManage->value))
                ->schema([
                    Select::make('type')->label(__('equipment.fields.type'))->required()->default('maintenance')
                        ->options(['maintenance' => __('equipment.events.maintenance'), 'repair' => __('equipment.events.repair')]),
                    Textarea::make('description')->label(__('equipment.fields.description'))->required(),
                    Fields::money('cost_cents', __('equipment.fields.cost')),
                    TextInput::make('performed_by')->label(__('equipment.fields.performed_by')),
                ])
                ->action(fn (Equipment $record, array $data) => $run(fn () => app(EquipmentActions::class)
                    ->service($record, $data['type'], $data['description'], (int) ($data['cost_cents'] ?? 0), $data['performed_by'] ?? null, auth()->user()))),
        ];
    }

    public static function getPages(): array
    {
        return [
            'index' => ListEquipment::route('/'),
            'create' => CreateEquipment::route('/create'),
            'view' => ViewEquipment::route('/{record}'),
            'edit' => EditEquipment::route('/{record}/edit'),
        ];
    }
}
