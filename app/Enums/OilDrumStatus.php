<?php

namespace App\Enums;

use App\Enums\Concerns\TranslatableEnum;
use Filament\Support\Contracts\HasLabel;

enum OilDrumStatus: string implements HasLabel
{
    use TranslatableEnum;

    public const TRANSLATION_KEY = 'oil_drum_status';

    case Closed = 'closed';
    case Open = 'open';
    case Empty = 'empty';
    case Blocked = 'blocked';
}
