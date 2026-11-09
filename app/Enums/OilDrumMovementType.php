<?php

namespace App\Enums;

use App\Enums\Concerns\TranslatableEnum;
use Filament\Support\Contracts\HasLabel;

enum OilDrumMovementType: string implements HasLabel
{
    use TranslatableEnum;

    public const TRANSLATION_KEY = 'oil_drum_movement_type';

    case Receipt = 'receipt';
    case Sale = 'sale';
    case Service = 'service';
    case Return = 'return';
    case Adjustment = 'adjustment';
    case TransferOut = 'transfer_out';
    case TransferIn = 'transfer_in';
}
