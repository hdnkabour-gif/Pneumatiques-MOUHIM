<?php

namespace App\Filament\Resources\Vehicles\OilFitments;

use App\Enums\CompatibilityStatus;
use App\Enums\Permission;
use App\Filament\Resources\Vehicles\OilFitments\Pages\CreateOilFitment;
use App\Filament\Resources\Vehicles\OilFitments\Pages\EditOilFitment;
use App\Filament\Resources\Vehicles\OilFitments\Pages\ListOilFitments;
use App\Filament\Resources\Vehicles\OilFitments\Pages\ViewOilFitment;
use App\Filament\Support\VehicleFields;
use App\Models\OilFitment;
use App\Models\VehicleMake;
use BackedEnum;
use Filament\Actions\ViewAction;
use Filament\Forms\Components\DatePicker;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Infolists\Components\TextEntry;
use Filament\Resources\Resource;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;

/** Compatibilities (§7.3): only "verified" rows are shown to users without management rights. */
class OilFitmentResource extends Resource
{
    /** Simplicity (§5): setup pages appear in the menu only for those who manage them. */
    public static function shouldRegisterNavigation(): bool
    {
        return parent::shouldRegisterNavigation() && (auth()->user()?->canAny([Permission::CompatibilitiesManage->value, Permission::CompatibilitiesVerify->value]) ?? false);
    }

    protected static ?string $model = OilFitment::class;

    protected static bool $hasTitleCaseModelLabel = false;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedBeaker;

    protected static ?int $navigationSort = 20;

    protected static ?string $slug = 'oil-fitments';

    public static function getModelLabel(): string
    {
        return __('vehicles.oil_fitments.label');
    }

    public static function getPluralModelLabel(): string
    {
        return __('vehicles.oil_fitments.plural');
    }

    public static function getNavigationGroup(): ?string
    {
        return __('app.navigation.vehicles');
    }

    public static function getEloquentQuery(): Builder
    {
        return parent::getEloquentQuery()
            ->with(['model.make', 'generation', 'engine', 'source', 'verifier'])
            ->when(! VehicleFields::canSeeUnverified(), fn (Builder $query) => $query->verified());
    }

    public static function form(Schema $schema): Schema
    {
        return $schema->components([
            Section::make()->columns(3)->schema([
                VehicleFields::model(),
                VehicleFields::generation(),
                VehicleFields::engine(),
                TextInput::make('engine_label')->label(__('vehicles.fields.engine_label'))->maxLength(255),
                TextInput::make('viscosity')->label(__('vehicles.fields.viscosity'))->maxLength(20)->placeholder('5W-30'),
                TextInput::make('acea')->label(__('vehicles.fields.acea'))->maxLength(50),
                TextInput::make('api')->label(__('vehicles.fields.api'))->maxLength(50),
                TextInput::make('oem_approval')->label(__('vehicles.fields.oem_approval'))->maxLength(100),
                TextInput::make('capacity_ml')->label(__('vehicles.fields.capacity'))->numeric()->minValue(0.5)->maxValue(20)->step(0.1)
                    ->formatStateUsing(fn ($state) => $state === null ? null : $state / 1000)
                    ->dehydrateStateUsing(fn ($state) => blank($state) ? null : (int) round(((float) str_replace(',', '.', (string) $state)) * 1000)),
                TextInput::make('oil_filter_ref')->label(__('vehicles.fields.oil_filter'))->maxLength(100),
            ]),
            Section::make(__('vehicles.fields.source'))->columns(2)->schema([
                VehicleFields::source(),
                DatePicker::make('source_checked_on')->label(__('vehicles.fields.source_checked_on'))->default(now()),
                Textarea::make('notes')->label(__('vehicles.fields.notes'))->columnSpanFull(),
            ]),
        ]);
    }

    public static function infolist(Schema $schema): Schema
    {
        return $schema->components([
            Section::make()->columns(3)->schema([
                TextEntry::make('vehicle')->label(__('vehicles.fields.model'))->state(fn (OilFitment $record) => $record->model->fullName())->weight('bold'),
                TextEntry::make('generation.label')->label(__('vehicles.fields.generation'))->placeholder('—'),
                TextEntry::make('engine.label')->label(__('vehicles.fields.engine'))->placeholder('—'),
                TextEntry::make('engine_label')->label(__('vehicles.fields.engine_label'))->placeholder('—'),
                TextEntry::make('viscosity')->label(__('vehicles.fields.viscosity'))->weight('bold')->size('lg')->placeholder('—'),
                TextEntry::make('norm')->label(__('vehicles.fields.oem_approval'))->state(fn (OilFitment $record) => trim(implode(' · ', array_filter([$record->acea ? 'ACEA '.$record->acea : null, $record->api ? 'API '.$record->api : null, $record->oem_approval]))) ?: null)->placeholder('—'),
                TextEntry::make('capacity')->label(__('vehicles.fields.capacity'))->state(fn (OilFitment $record) => $record->capacityLitres())->placeholder('—'),
                TextEntry::make('oil_filter_ref')->label(__('vehicles.fields.oil_filter'))->placeholder('—'),
                TextEntry::make('status')->label(__('vehicles.fields.status'))->badge()->color(fn (CompatibilityStatus $state) => $state->color()),
                TextEntry::make('verifier.name')->label(__('vehicles.fields.verified_by'))->placeholder('—'),
                TextEntry::make('verified_at')->label(__('vehicles.fields.verified_at'))->dateTime('d/m/Y H:i')->placeholder('—'),
                TextEntry::make('source.title')->label(__('vehicles.fields.source'))->state(fn (OilFitment $record) => $record->source?->label())
                    ->url(fn (OilFitment $record) => $record->source?->url, shouldOpenInNewTab: true)->placeholder('—')->columnSpan(2),
                TextEntry::make('source_checked_on')->label(__('vehicles.fields.source_checked_on'))->date('d/m/Y')->placeholder('—'),
                TextEntry::make('notes')->label(__('vehicles.fields.notes'))->placeholder('—')->columnSpanFull(),
            ]),
        ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->defaultSort('model_id')
            ->columns([
                TextColumn::make('model.name')->label(__('vehicles.fields.model'))->weight('bold')
                    ->state(fn (OilFitment $record) => $record->model->fullName())
                    ->description(fn (OilFitment $record) => $record->generation?->label)
                    ->searchable(query: fn (Builder $query, string $search) => $query->whereHas('model', fn ($m) => $m
                        ->whereRaw('lower(vehicle_models.name) like ?', ['%'.mb_strtolower($search).'%'])
                        ->orWhereHas('make', fn ($k) => $k->whereRaw('lower(name) like ?', ['%'.mb_strtolower($search).'%'])))),
                TextColumn::make('engine_label')->label(__('vehicles.fields.engine_label'))->wrap()->placeholder('—'),
                TextColumn::make('viscosity')->label(__('vehicles.fields.viscosity'))->weight('bold')->placeholder('—'),
                TextColumn::make('norm')->label(__('vehicles.fields.oem_approval'))->state(fn (OilFitment $record) => trim(implode(' ', array_filter([$record->acea ? 'ACEA '.$record->acea : null, $record->api ? 'API '.$record->api : null, $record->oem_approval]))) ?: null)->placeholder('—'),
                TextColumn::make('capacity_ml')->label(__('vehicles.fields.capacity'))->formatStateUsing(fn ($state, OilFitment $record) => $record->capacityLitres())->placeholder('—'),
                TextColumn::make('status')->label(__('vehicles.fields.status'))->badge()->color(fn (CompatibilityStatus $state) => $state->color()),
                TextColumn::make('source.external_id')->label(__('vehicles.fields.source'))->toggleable(),
            ])
            ->filters([
                SelectFilter::make('status')->label(__('vehicles.fields.status'))->options(CompatibilityStatus::class)
                    ->visible(fn () => VehicleFields::canSeeUnverified()),
                SelectFilter::make('make')->label(__('vehicles.fields.make'))
                    ->options(fn () => VehicleMake::query()->orderBy('name')->pluck('name', 'id'))
                    ->query(fn (Builder $query, array $data) => $data['value'] ? $query->whereHas('model', fn ($m) => $m->where('make_id', $data['value'])) : $query),
            ])
            ->recordActions([ViewAction::make()]);
    }

    public static function getPages(): array
    {
        return [
            'index' => ListOilFitments::route('/'),
            'create' => CreateOilFitment::route('/create'),
            'view' => ViewOilFitment::route('/{record}'),
            'edit' => EditOilFitment::route('/{record}/edit'),
        ];
    }
}
