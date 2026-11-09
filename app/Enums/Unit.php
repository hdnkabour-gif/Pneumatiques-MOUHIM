<?php

namespace App\Enums;

use App\Enums\Concerns\TranslatableEnum;
use Filament\Support\Contracts\HasLabel;

enum Unit: string implements HasLabel
{
    use TranslatableEnum;

    public const TRANSLATION_KEY = 'unit';

    case Piece = 'piece';
    case Can = 'can';
    case Kit = 'kit';
    case Set = 'set';
    case Litre = 'litre'; // bulk oil sold by the litre from drums (D-06 revised)
}
