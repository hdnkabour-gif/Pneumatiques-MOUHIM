<?php

namespace App\Enums;

use App\Enums\Concerns\TranslatableEnum;
use Filament\Support\Contracts\HasLabel;

enum OilType: string implements HasLabel
{
    use TranslatableEnum;

    public const TRANSLATION_KEY = 'oil_type';

    case Synthetic = 'synthetic';
    case SemiSynthetic = 'semi_synthetic';
    case Mineral = 'mineral';
}
