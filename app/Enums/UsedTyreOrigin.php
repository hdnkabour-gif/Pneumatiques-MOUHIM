<?php

namespace App\Enums;

use App\Enums\Concerns\TranslatableEnum;
use Filament\Support\Contracts\HasLabel;

enum UsedTyreOrigin: string implements HasLabel
{
    use TranslatableEnum;

    public const TRANSLATION_KEY = 'used_tyre_origin';

    case Supplier = 'supplier';
    case TradeIn = 'trade_in';
    case Other = 'other';
}
