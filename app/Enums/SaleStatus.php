<?php

namespace App\Enums;

use App\Enums\Concerns\TranslatableEnum;
use Filament\Support\Contracts\HasLabel;

enum SaleStatus: string implements HasLabel
{
    use TranslatableEnum;

    public const TRANSLATION_KEY = 'sale_status';

    case Draft = 'draft';
    case Confirmed = 'confirmed';
    case Cancelled = 'cancelled';
}
