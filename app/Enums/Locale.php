<?php

namespace App\Enums;

use Filament\Support\Contracts\HasLabel;

enum Locale: string implements HasLabel
{
    case French = 'fr';
    case Arabic = 'ar';

    public function label(): string
    {
        return match ($this) {
            self::French => 'Français',
            self::Arabic => 'العربية',
        };
    }

    public function getLabel(): string
    {
        return $this->label();
    }

    public function isRtl(): bool
    {
        return $this === self::Arabic;
    }
}
