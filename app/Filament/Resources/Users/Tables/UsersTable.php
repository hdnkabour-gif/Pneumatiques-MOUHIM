<?php

namespace App\Filament\Resources\Users\Tables;

use App\Enums\RoleName;
use App\Models\User;
use Filament\Actions\EditAction;
use Filament\Tables\Columns\IconColumn;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Filters\TernaryFilter;
use Filament\Tables\Table;

class UsersTable
{
    public static function configure(Table $table): Table
    {
        return $table
            ->defaultSort('name')
            ->columns([
                TextColumn::make('name')->label(__('users.fields.name'))->searchable()->sortable()->weight('bold'),
                TextColumn::make('email')->label(__('users.fields.email'))->searchable()->toggleable(),
                TextColumn::make('role')
                    ->label(__('users.fields.role'))
                    ->badge()
                    ->state(fn (User $record) => $record->roles->map(fn ($r) => RoleName::tryFrom($r->name)?->label() ?? $r->name)->all()),
                TextColumn::make('sites.code')->label(__('users.fields.sites'))->badge(),
                TextColumn::make('locale')->label(__('users.fields.locale'))->badge()->toggleable(),
                IconColumn::make('is_active')->label(__('users.fields.is_active'))->boolean(),
                TextColumn::make('last_login_at')->label(__('users.fields.last_login_at'))->since()->toggleable(),
            ])
            ->filters([
                SelectFilter::make('roles')->label(__('users.fields.role'))->relationship('roles', 'name')
                    ->getOptionLabelFromRecordUsing(fn ($record) => RoleName::tryFrom($record->name)?->label() ?? $record->name),
                SelectFilter::make('sites')->label(__('users.fields.sites'))->relationship('sites', 'code')->multiple(),
                TernaryFilter::make('is_active')->label(__('users.fields.is_active')),
            ])
            ->recordActions([
                EditAction::make(),
            ]);
    }
}
