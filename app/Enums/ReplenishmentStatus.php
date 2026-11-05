<?php

namespace App\Enums;

use App\Enums\Concerns\TranslatableEnum;
use Filament\Support\Contracts\HasLabel;

enum ReplenishmentStatus: string implements HasLabel
{
    use TranslatableEnum;

    public const TRANSLATION_KEY = 'replenishment_status';

    case Pending = 'pending';
    case Converted = 'converted';
    case Cancelled = 'cancelled';
}
