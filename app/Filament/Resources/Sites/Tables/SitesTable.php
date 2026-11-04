<?php

namespace App\Filament\Resources\Sites\Tables;

use Filament\Actions\EditAction;
use Filament\Tables\Columns\IconColumn;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;

class SitesTable
{
    public static function configure(Table $table): Table
    {
        return $table
            ->defaultSort('code')
            ->columns([
                TextColumn::make('code')->label(__('sites.fields.code'))->searchable()->sortable()->weight('bold'),
                TextColumn::make('name')->label(__('sites.fields.name'))->searchable(),
                TextColumn::make('type')->label(__('sites.fields.type'))->badge(),
                IconColumn::make('has_workshop')->label(__('sites.fields.has_workshop'))->boolean(),
                IconColumn::make('sales_enabled')->label(__('sites.fields.sales_enabled'))->boolean(),
                TextColumn::make('city')->label(__('sites.fields.city'))->toggleable(),
                TextColumn::make('phone')->label(__('sites.fields.phone'))->toggleable(),
                IconColumn::make('is_active')->label(__('sites.fields.is_active'))->boolean(),
            ])
            ->recordActions([
                EditAction::make(),
            ]);
    }
}
