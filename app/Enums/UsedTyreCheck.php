<?php

namespace App\Enums;

use App\Enums\Concerns\TranslatableEnum;
use Filament\Support\Contracts\HasLabel;

enum UsedTyreCheck: string implements HasLabel
{
    use TranslatableEnum;

    public const TRANSLATION_KEY = 'used_tyre_check';

    case Sidewalls = 'sidewalls';
    case Cracks = 'cracks';
    case Bulges = 'bulges';
    case Deformation = 'deformation';
    case Bead = 'bead';
    case PreviousRepairs = 'previous_repairs';
    case IrregularWear = 'irregular_wear';
    case TreadDepth = 'tread_depth';
    case Age = 'age';

    /** Checks answered automatically from measured values rather than by eye. */
    public function isMeasured(): bool
    {
        return in_array($this, [self::TreadDepth, self::Age], true);
    }
}
