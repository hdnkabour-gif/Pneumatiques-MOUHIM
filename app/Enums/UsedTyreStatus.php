<?php

namespace App\Enums;

use App\Enums\Concerns\TranslatableEnum;
use Filament\Support\Contracts\HasLabel;

enum UsedTyreStatus: string implements HasLabel
{
    use TranslatableEnum;

    public const TRANSLATION_KEY = 'used_tyre_status';

    case PendingInspection = 'pending_inspection';
    case InStock = 'in_stock';
    case Reserved = 'reserved';
    case Sold = 'sold';
    case Unsellable = 'unsellable';
    case Scrapped = 'scrapped';

    /** Only inspected, available tyres may be offered for sale (§5.4). */
    public function isSellable(): bool
    {
        return $this === self::InStock;
    }
}
