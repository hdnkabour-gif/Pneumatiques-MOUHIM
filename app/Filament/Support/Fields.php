<?php

namespace App\Filament\Support;

use App\Enums\Permission;
use App\Support\Decimal;
use App\Support\Money;
use Filament\Forms\Components\TextInput;
use Filament\Tables\Columns\TextColumn;

/** Reusable form fields and columns (money in cents, cost visibility). */
final class Fields
{
    /** Amount typed as "1 234,50", stored as integer cents. */
    public static function money(string $name, string $label): TextInput
    {
        return TextInput::make($name)
            ->label($label)
            ->suffix('MAD')
            ->inputMode('decimal')
            ->rule('regex:/^\s*\d[\d\s]*([.,]\d{1,2})?\s*$/')
            ->formatStateUsing(fn ($state) => $state === null || $state === '' ? null : Decimal::fromCents((int) $state))
            ->dehydrateStateUsing(fn ($state) => blank($state) ? null : Decimal::toCents((string) $state));
    }

    public static function moneyColumn(string $name, string $label): TextColumn
    {
        return TextColumn::make($name)
            ->label($label)
            ->formatStateUsing(fn ($state) => Money::format($state === null ? null : (int) $state))
            ->alignEnd();
    }

    /** Purchase costs, average cost, margins and stock value (§3: hidden from sellers). */
    public static function canSeeCosts(): bool
    {
        return auth()->user()?->can(Permission::CostsView->value) ?? false;
    }
}
