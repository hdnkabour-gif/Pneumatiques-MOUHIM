<?php

namespace App\Enums;

use App\Enums\Concerns\TranslatableEnum;
use Filament\Support\Contracts\HasLabel;

enum CustomerType: string implements HasLabel
{
    use TranslatableEnum;

    public const TRANSLATION_KEY = 'customer_type';

    case Individual = 'individual';
    case Company = 'company';
}
