<?php

namespace App\Enums;

use App\Enums\Concerns\TranslatableEnum;
use Filament\Support\Contracts\HasLabel;

enum PaymentMethod: string implements HasLabel
{
    use TranslatableEnum;

    public const TRANSLATION_KEY = 'payment_method';

    case Cash = 'cash';
    case Card = 'card';
    case Cheque = 'cheque';
    case Transfer = 'transfer';
    case CreditNote = 'credit_note'; // voucher from a return (exchange)
}
