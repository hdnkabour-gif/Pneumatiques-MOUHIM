<?php

namespace App\Filament\Resources\Roles;

use App\Enums\Permission;
use App\Enums\RoleName;
use App\Filament\Resources\Roles\Pages\CreateRole;
use App\Filament\Resources\Roles\Pages\EditRole;
use App\Filament\Resources\Roles\Pages\ListRoles;
use BackedEnum;
use Filament\Actions\DeleteAction;
use Filament\Actions\EditAction;
use Filament\Forms\Components\CheckboxList;
use Filament\Forms\Components\TextInput;
use Filament\Resources\Resource;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use Spatie\Permission\Models\Permission as PermissionModel;
use Spatie\Permission\Models\Role;

/** Roles and their permissions, editable by the owner (§3). */
class RoleResource extends Resource
{
    protected static ?string $model = Role::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedShieldCheck;

    protected static ?int $navigationSort = 30;

    public static function getModelLabel(): string
    {
        return __('roles.resource.label');
    }

    public static function getPluralModelLabel(): string
    {
        return __('roles.resource.plural');
    }

    public static function getNavigationGroup(): ?string
    {
        return __('app.navigation.administration');
    }

    public static function label(Role $role): string
    {
        return RoleName::tryFrom($role->name)?->label() ?? $role->name;
    }

    public static function form(Schema $schema): Schema
    {
        return $schema->components([
            Section::make()->schema([
                TextInput::make('name')
                    ->label(__('roles.resource.name'))
                    ->required()
                    ->maxLength(100)
                    ->unique(ignoreRecord: true)
                    // Default role identifiers are referenced by code and cannot be renamed.
                    ->disabled(fn (?Role $record) => $record && RoleName::tryFrom($record->name) !== null)
                    // Disabled fields are not saved: showing the translated label is safe.
                    ->formatStateUsing(fn (?string $state) => $state ? (RoleName::tryFrom($state)?->label() ?? $state) : null),
                CheckboxList::make('permissions')
                    ->label(__('roles.resource.permissions'))
                    ->relationship('permissions', 'name')
                    ->getOptionLabelFromRecordUsing(fn ($record) => Permission::tryFrom($record->name)?->label() ?? $record->name)
                    ->descriptions(fn () => PermissionModel::query()->pluck('name', 'id')
                        ->map(fn (string $name) => Permission::tryFrom($name)?->group() ?? '')->all())
                    ->columns(2)
                    ->bulkToggleable(),
            ]),
        ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->modifyQueryUsing(fn ($query) => $query->withCount(['permissions', 'users']))
            ->columns([
                TextColumn::make('name')->label(__('roles.resource.name'))
                    ->formatStateUsing(fn (string $state) => RoleName::tryFrom($state)?->label() ?? $state)
                    ->weight('bold'),
                TextColumn::make('permissions_count')->label(__('roles.resource.permissions'))->badge(),
                TextColumn::make('users_count')->label(__('roles.resource.users_count'))->badge(),
            ])
            ->recordActions([
                EditAction::make(),
                DeleteAction::make(),
            ]);
    }

    public static function getPages(): array
    {
        return [
            'index' => ListRoles::route('/'),
            'create' => CreateRole::route('/create'),
            'edit' => EditRole::route('/{record}/edit'),
        ];
    }
}
