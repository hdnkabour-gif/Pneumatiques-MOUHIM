<?php

namespace App\Enums;

use App\Enums\Concerns\TranslatableEnum;
use Filament\Support\Contracts\HasLabel;

enum InventoryCountStatus: string implements HasLabel
{
    use TranslatableEnum;

    public const TRANSLATION_KEY = 'inventory_count_status';

    case Draft = 'draft';
    case Submitted = 'submitted';
    case Approved = 'approved';
    case Cancelled = 'cancelled';
}
