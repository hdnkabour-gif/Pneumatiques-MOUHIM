<?php

namespace App\Enums;

use App\Enums\Concerns\TranslatableEnum;
use Filament\Support\Contracts\HasLabel;

enum TyreUsage: string implements HasLabel
{
    use TranslatableEnum;

    public const TRANSLATION_KEY = 'tyre_usage';

    case Car = 'car';
    case Suv = 'suv';
    case Van = 'van';
    case Truck = 'truck';
    case Moto = 'moto';
}
