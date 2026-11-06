<?php

namespace App\Enums;

use App\Enums\Concerns\TranslatableEnum;
use Filament\Support\Contracts\HasLabel;

enum QuoteStatus: string implements HasLabel
{
    use TranslatableEnum;

    public const TRANSLATION_KEY = 'quote_status';

    case Sent = 'sent';
    case Refused = 'refused';
    case Expired = 'expired';
    case Converted = 'converted';
}
