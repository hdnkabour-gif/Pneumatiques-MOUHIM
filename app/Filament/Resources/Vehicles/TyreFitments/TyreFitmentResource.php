<?php

namespace App\Filament\Resources\Vehicles\TyreFitments;

use App\Enums\CompatibilityStatus;
use App\Filament\Resources\Vehicles\TyreFitments\Pages\CreateTyreFitment;
use App\Filament\Resources\Vehicles\TyreFitments\Pages\EditTyreFitment;
use App\Filament\Resources\Vehicles\TyreFitments\Pages\ListTyreFitments;
use App\Filament\Resources\Vehicles\TyreFitments\Pages\ViewTyreFitment;
use App\Filament\Support\VehicleFields;
use App\Models\TyreFitment;
use App\Models\VehicleMake;
use BackedEnum;
use Filament\Actions\ViewAction;
use Filament\Forms\Components\DatePicker;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
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
class TyreFitmentResource extends Resource
{
    protected static ?string $model = TyreFitment::class;

    protected static bool $hasTitleCaseModelLabel = false;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedLifebuoy;

    protected static ?int $navigationSort = 10;

    protected static ?string $slug = 'tyre-fitments';

    public static function getModelLabel(): string
    {
        return __('vehicles.tyre_fitments.label');
    }

    public static function getPluralModelLabel(): string
    {
        return __('vehicles.tyre_fitments.plural');
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
                TextInput::make('trim')->label(__('vehicles.fields.trim'))->maxLength(255),
                Select::make('position')->label(__('vehicles.fields.position'))->options(__('vehicles.positions'))->default('both')->required(),
                TextInput::make('width')->label(__('vehicles.fields.width'))->integer()->minValue(100)->maxValue(455)->required(),
                TextInput::make('aspect_ratio')->label(__('vehicles.fields.aspect_ratio'))->integer()->minValue(10)->maxValue(95),
                TextInput::make('rim_diameter')->label(__('vehicles.fields.rim_diameter'))->numeric()->minValue(8)->maxValue(26)->required(),
                TextInput::make('load_index')->label(__('vehicles.fields.load_index'))->maxLength(10),
                TextInput::make('speed_index')->label(__('vehicles.fields.speed_index'))->maxLength(3),
                Toggle::make('is_xl')->label(__('vehicles.fields.is_xl')),
                Toggle::make('is_run_flat')->label(__('vehicles.fields.is_run_flat')),
                Toggle::make('is_reinforced')->label(__('vehicles.fields.is_reinforced')),
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
                TextEntry::make('vehicle')->label(__('vehicles.fields.model'))->state(fn (TyreFitment $record) => $record->model->fullName())->weight('bold'),
                TextEntry::make('generation.label')->label(__('vehicles.fields.generation'))->placeholder('—'),
                TextEntry::make('engine.label')->label(__('vehicles.fields.engine'))->placeholder('—'),
                TextEntry::make('trim')->label(__('vehicles.fields.trim'))->placeholder('—'),
                TextEntry::make('size')->label(__('vehicles.fields.size'))->state(fn (TyreFitment $record) => $record->sizeLabel())->weight('bold')->size('lg'),
                TextEntry::make('position')->label(__('vehicles.fields.position'))->formatStateUsing(fn (string $state) => __("vehicles.positions.{$state}")),
                TextEntry::make('status')->label(__('vehicles.fields.status'))->badge()->color(fn (CompatibilityStatus $state) => $state->color()),
                TextEntry::make('verifier.name')->label(__('vehicles.fields.verified_by'))->placeholder('—'),
                TextEntry::make('verified_at')->label(__('vehicles.fields.verified_at'))->dateTime('d/m/Y H:i')->placeholder('—'),
                TextEntry::make('source.title')->label(__('vehicles.fields.source'))->state(fn (TyreFitment $record) => $record->source?->label())
                    ->url(fn (TyreFitment $record) => $record->source?->url, shouldOpenInNewTab: true)->placeholder('—')->columnSpan(2),
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
                    ->state(fn (TyreFitment $record) => $record->model->fullName())
                    ->description(fn (TyreFitment $record) => $record->generation?->label)
                    ->searchable(query: fn (Builder $query, string $search) => $query->whereHas('model', fn ($m) => $m
                        ->whereRaw('lower(vehicle_models.name) like ?', ['%'.mb_strtolower($search).'%'])
                        ->orWhereHas('make', fn ($k) => $k->whereRaw('lower(name) like ?', ['%'.mb_strtolower($search).'%'])))),
                TextColumn::make('trim')->label(__('vehicles.fields.trim'))->wrap()->placeholder('—'),
                TextColumn::make('size')->label(__('vehicles.fields.size'))->state(fn (TyreFitment $record) => $record->sizeLabel())->weight('bold'),
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
            'index' => ListTyreFitments::route('/'),
            'create' => CreateTyreFitment::route('/create'),
            'view' => ViewTyreFitment::route('/{record}'),
            'edit' => EditTyreFitment::route('/{record}/edit'),
        ];
    }
}
