<?php

namespace App\Filament\Resources\Vehicles\CompatibilitySources;

use App\Filament\Resources\Vehicles\CompatibilitySources\Pages\ManageCompatibilitySources;
use App\Models\CompatibilitySource;
use BackedEnum;
use Filament\Actions\EditAction;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;

class CompatibilitySourceResource extends Resource
{
    protected static ?string $model = CompatibilitySource::class;

    /** French labels keep their own capitalisation. */
    protected static bool $hasTitleCaseModelLabel = false;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedBookOpen;

    protected static ?int $navigationSort = 80;

    protected static ?string $slug = 'compatibility-sources';

    public static function getModelLabel(): string
    {
        return __('vehicles.sources.label');
    }

    public static function getPluralModelLabel(): string
    {
        return __('vehicles.sources.plural');
    }

    public static function getNavigationGroup(): ?string
    {
        return __('app.navigation.vehicles');
    }

    public static function form(Schema $schema): Schema
    {
        return $schema->columns(2)->components([
            TextInput::make('external_id')->label(__('vehicles.fields.external_id'))->maxLength(60)->unique(ignoreRecord: true),
            TextInput::make('publisher')->label(__('vehicles.fields.publisher'))->required()->maxLength(255),
            TextInput::make('title')->label(__('vehicles.fields.title'))->required()->maxLength(255)->columnSpanFull(),
            TextInput::make('url')->label(__('vehicles.fields.url'))->url()->maxLength(500)->columnSpanFull(),
            TextInput::make('source_type')->label(__('vehicles.fields.source_type'))->maxLength(255),
            TextInput::make('source_date')->label(__('vehicles.fields.source_date'))->maxLength(30),
            TextInput::make('scope')->label(__('vehicles.fields.scope'))->maxLength(255),
            TextInput::make('reliability')->label(__('vehicles.fields.reliability'))->maxLength(255),
            Textarea::make('notes')->label(__('vehicles.fields.notes'))->columnSpanFull(),
        ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('external_id')->label(__('vehicles.fields.external_id'))->searchable()->weight('bold'),
                TextColumn::make('publisher')->label(__('vehicles.fields.publisher'))->searchable(),
                TextColumn::make('title')->label(__('vehicles.fields.title'))->searchable()->wrap()
                    ->url(fn ($record) => $record->url, shouldOpenInNewTab: true),
                TextColumn::make('reliability')->label(__('vehicles.fields.reliability'))->wrap()->toggleable(),
                TextColumn::make('source_date')->label(__('vehicles.fields.source_date'))->placeholder('—'),
            ])
            ->filters([])
            ->recordActions([EditAction::make()]);
    }

    public static function getPages(): array
    {
        return ['index' => ManageCompatibilitySources::route('/')];
    }
}
