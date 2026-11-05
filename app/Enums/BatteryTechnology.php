<?php

namespace App\Enums;

use App\Enums\Concerns\TranslatableEnum;
use Filament\Support\Contracts\HasLabel;

enum BatteryTechnology: string implements HasLabel
{
    use TranslatableEnum;

    public const TRANSLATION_KEY = 'battery_technology';

    case Standard = 'standard';
    case Efb = 'efb';
    case Agm = 'agm';
}
