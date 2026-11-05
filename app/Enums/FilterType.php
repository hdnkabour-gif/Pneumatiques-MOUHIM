<?php

namespace App\Enums;

use App\Enums\Concerns\TranslatableEnum;
use Filament\Support\Contracts\HasLabel;

enum FilterType: string implements HasLabel
{
    use TranslatableEnum;

    public const TRANSLATION_KEY = 'filter_type';

    case Oil = 'oil';
    case Air = 'air';
    case Cabin = 'cabin';
    case Fuel = 'fuel';
}
