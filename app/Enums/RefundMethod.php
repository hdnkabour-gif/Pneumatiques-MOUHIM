<?php

namespace App\Enums;

use App\Enums\Concerns\TranslatableEnum;
use Filament\Support\Contracts\HasLabel;

/** What the customer gets back for a credit note. */
enum RefundMethod: string implements HasLabel
{
    use TranslatableEnum;

    public const TRANSLATION_KEY = 'refund_method';

    case Cash = 'cash';         // money given back
    case Account = 'account';   // deducted from what the customer owes
    case Voucher = 'voucher';   // exchange: used to pay another sale
}
