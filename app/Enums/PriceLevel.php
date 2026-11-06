<?php

namespace App\Enums;

use App\Enums\Concerns\TranslatableEnum;
use Filament\Support\Contracts\HasLabel;

enum PriceLevel: string implements HasLabel
{
    use TranslatableEnum;

    public const TRANSLATION_KEY = 'price_level';

    case Retail = 'retail';
    case Pro = 'pro';
    case Reseller = 'reseller';
}
