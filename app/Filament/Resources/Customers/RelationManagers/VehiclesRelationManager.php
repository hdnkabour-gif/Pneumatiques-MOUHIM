<?php

namespace App\Filament\Resources\Customers\RelationManagers;

use App\Filament\Support\VehicleFields;
use App\Models\CustomerVehicle;
use App\Support\Normalize;
use App\Support\TyreSize;
use Filament\Actions\CreateAction;
use Filament\Actions\EditAction;
use Filament\Forms\Components\DatePicker;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Resources\RelationManagers\RelationManager;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Model;

class VehiclesRelationManager extends RelationManager
{
    protected static string $relationship = 'vehicles';

    public static function getTitle(Model $ownerRecord, string $pageClass): string
    {
        return __('customers.vehicles.title');
    }

    public function isReadOnly(): bool
    {
        return false;
    }

    public function form(Schema $schema): Schema
    {
        return $schema->columns(2)->components([
            TextInput::make('plate')->label(__('customers.vehicles.plate'))->required()->maxLength(30),
            Select::make('vehicle_model_id')->label(__('vehicles.fields.model'))
                ->options(fn () => VehicleFields::modelOptions())->searchable(),
            TextInput::make('description')->label(__('customers.vehicles.description'))
                ->helperText(__('customers.vehicles.description_help')),
            TextInput::make('year')->label(__('customers.vehicles.year'))->integer()->minValue(1970)->maxValue((int) date('Y') + 1),
            TextInput::make('mileage')->label(__('customers.vehicles.mileage'))->integer()->minValue(0)->suffix('km'),
            Section::make(__('app.more_details'))->collapsed()->columns(2)->columnSpanFull()->schema([
                TextInput::make('tyre_size_front')->label(__('customers.vehicles.tyre_size_front'))->placeholder('205/55 R16')
                    ->rule(fn () => fn ($attr, $value, $fail) => filled($value) && ! TyreSize::parse($value) ? $fail(__('customers.errors.tyre_size')) : null),
                TextInput::make('tyre_size_rear')->label(__('customers.vehicles.tyre_size_rear'))
                    ->rule(fn () => fn ($attr, $value, $fail) => filled($value) && ! TyreSize::parse($value) ? $fail(__('customers.errors.tyre_size')) : null),
                TextInput::make('next_oil_change_km')->label(__('customers.vehicles.next_oil_change_km'))->integer()->suffix('km'),
                DatePicker::make('next_oil_change_on')->label(__('customers.vehicles.next_oil_change_on')),
                Textarea::make('notes')->label(__('customers.fields.notes'))->columnSpanFull(),
            ]),
        ]);
    }

    public function table(Table $table): Table
    {
        return $table
            ->modifyQueryUsing(fn ($query) => $query->with('vehicleModel.make'))
            ->columns([
                TextColumn::make('plate')->label(__('customers.vehicles.plate'))->weight('bold')
                    ->formatStateUsing(fn ($state) => Normalize::plateDisplay($state)),
                TextColumn::make('model')->label(__('vehicles.fields.model'))->state(fn (CustomerVehicle $record) => $record->label()),
                TextColumn::make('mileage')->label(__('customers.vehicles.mileage'))->numeric(thousandsSeparator: ' ')->suffix(' km')->placeholder('—'),
                TextColumn::make('next_oil_change_km')->label(__('customers.vehicles.next_oil_change_km'))->numeric(thousandsSeparator: ' ')->placeholder('—'),
            ])
            ->headerActions([CreateAction::make()])
            ->recordActions([EditAction::make()]);
    }
}
