<?php

namespace App\Filament\Resources\Users\Schemas;

use App\Enums\Locale;
use App\Enums\RoleName;
use App\Models\Site;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Schemas\Schema;
use Illuminate\Validation\Rules\Password;
use Spatie\Permission\Models\Role;

class UserForm
{
    public static function configure(Schema $schema): Schema
    {
        return $schema
            ->components([
                Section::make()
                    ->columns(2)
                    ->schema([
                        TextInput::make('name')
                            ->label(__('users.fields.name'))
                            ->required()
                            ->maxLength(255),
                        TextInput::make('email')
                            ->label(__('users.fields.email'))
                            ->email()
                            ->required()
                            ->maxLength(255)
                            ->unique(ignoreRecord: true),
                        TextInput::make('password')
                            ->label(__('users.fields.password'))
                            ->password()
                            ->revealable()
                            ->rule(Password::min(10)->letters()->numbers())
                            ->required(fn (string $operation) => $operation === 'create')
                            ->helperText(fn (string $operation) => $operation === 'edit' ? __('users.fields.password_help') : null)
                            ->dehydrated(fn (?string $state) => filled($state)),
                        Select::make('role')
                            ->label(__('users.fields.role'))
                            ->options(fn () => self::roleOptions())
                            ->required()
                            ->native(false),
                        Select::make('sites')
                            ->label(__('users.fields.sites'))
                            ->options(fn () => self::siteOptions())
                            ->multiple()
                            ->required()
                            ->live(),
                        Select::make('default_site_id')
                            ->label(__('users.fields.default_site'))
                            ->options(fn (Get $get) => array_intersect_key(self::siteOptions(), array_flip(array_map('intval', $get('sites') ?? []))))
                            ->in(fn (Get $get) => array_map('intval', $get('sites') ?? [])),
                        Select::make('locale')
                            ->label(__('users.fields.locale'))
                            ->options(Locale::class)
                            ->default(Locale::French)
                            ->required(),
                        Toggle::make('is_active')
                            ->label(__('users.fields.is_active'))
                            ->default(true),
                    ]),
                Section::make(__('app.more_details'))
                    ->collapsed()
                    ->schema([
                        TextInput::make('phone')->label(__('users.fields.phone'))->tel()->maxLength(30),
                        TextInput::make('approval_pin')->label(__('approvals.fields.approval_pin'))
                            ->helperText(__('approvals.fields.approval_pin_help'))
                            ->password()->revealable()->rule('regex:/^\\d{4,8}$/')->autocomplete('new-password')
                            ->dehydrated(fn (?string $state) => filled($state))
                            ->visible(fn () => auth()->user()?->isOwner()),
                    ]),
            ]);
    }

    /** @return array<string, string> */
    public static function roleOptions(): array
    {
        $actor = auth()->user();

        return Role::query()->orderBy('id')->pluck('name')
            ->reject(fn (string $name) => $name === RoleName::Owner->value && ! $actor?->isOwner())
            ->mapWithKeys(fn (string $name) => [$name => RoleName::tryFrom($name)?->label() ?? $name])
            ->all();
    }

    /** @return array<int, string> */
    public static function siteOptions(): array
    {
        return Site::query()->visibleTo(auth()->user())->orderBy('code')
            ->get(['id', 'code', 'name'])
            ->mapWithKeys(fn (Site $site) => [$site->id => "{$site->code} – {$site->name}"])
            ->all();
    }
}
