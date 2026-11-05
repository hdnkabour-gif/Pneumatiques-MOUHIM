<?php

namespace App\Enums;

use App\Enums\Concerns\TranslatableEnum;
use Filament\Support\Contracts\HasLabel;

enum TransferStatus: string implements HasLabel
{
    use TranslatableEnum;

    public const TRANSLATION_KEY = 'transfer_status';

    case InTransit = 'in_transit';
    case Received = 'received';
    case Cancelled = 'cancelled';
}
