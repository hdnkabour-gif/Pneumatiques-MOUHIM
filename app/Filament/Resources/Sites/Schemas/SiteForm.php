<?php

namespace App\Filament\Resources\Sites\Schemas;

use App\Enums\SiteType;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;

class SiteForm
{
    public static function configure(Schema $schema): Schema
    {
        return $schema
            ->components([
                Section::make()
                    ->columns(2)
                    ->schema([
                        TextInput::make('code')
                            ->label(__('sites.fields.code'))
                            ->required()
                            ->maxLength(10)
                            ->unique(ignoreRecord: true)
                            ->dehydrateStateUsing(fn (?string $state) => strtoupper(trim((string) $state))),
                        TextInput::make('name')
                            ->label(__('sites.fields.name'))
                            ->required()
                            ->maxLength(255),
                        Select::make('type')
                            ->label(__('sites.fields.type'))
                            ->options(SiteType::class)
                            ->default(SiteType::Store)
                            ->required(),
                        Toggle::make('is_active')
                            ->label(__('sites.fields.is_active'))
                            ->default(true),
                        Toggle::make('has_workshop')
                            ->label(__('sites.fields.has_workshop'))
                            ->default(true),
                        Toggle::make('sales_enabled')
                            ->label(__('sites.fields.sales_enabled'))
                            ->default(true),
                    ]),
                Section::make(__('app.more_details'))
                    ->collapsed()
                    ->columns(2)
                    ->schema([
                        TextInput::make('address')->label(__('sites.fields.address'))->maxLength(255),
                        TextInput::make('city')->label(__('sites.fields.city'))->maxLength(255),
                        TextInput::make('phone')->label(__('sites.fields.phone'))->tel()->maxLength(30),
                    ]),
            ]);
    }
}
