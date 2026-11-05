<?php

namespace App\Enums;

use App\Enums\Concerns\TranslatableEnum;
use Filament\Support\Contracts\HasLabel;

enum TyreSeason: string implements HasLabel
{
    use TranslatableEnum;

    public const TRANSLATION_KEY = 'tyre_season';

    case Summer = 'summer';
    case Winter = 'winter';
    case AllSeason = 'all_season';
}
