<?php

namespace App\Enums;

use App\Enums\Concerns\TranslatableEnum;
use Filament\Support\Contracts\HasLabel;

/** Commercial documents stored in `sales` (§10.2). */
enum SaleKind: string implements HasLabel
{
    use TranslatableEnum;

    public const TRANSLATION_KEY = 'sale_kind';

    case Invoice = 'invoice';
    case Quote = 'quote';
    case DeliveryNote = 'delivery_note'; // BL (goods) or BT (with services), invoiced later
    case CreditNote = 'credit_note';

    /** Documents that take stock out and count in the customer's balance. */
    public function movesStock(): bool
    {
        return in_array($this, [self::Invoice, self::DeliveryNote], true);
    }
}
