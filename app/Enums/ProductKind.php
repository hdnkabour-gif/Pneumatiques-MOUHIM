<?php

namespace App\Enums;

use App\Enums\Concerns\TranslatableEnum;
use Filament\Support\Contracts\HasLabel;

enum ProductKind: string implements HasLabel
{
    use TranslatableEnum;

    public const TRANSLATION_KEY = 'product_kind';

    case Goods = 'goods';
    case Service = 'service';
    case Bundle = 'bundle';
}
