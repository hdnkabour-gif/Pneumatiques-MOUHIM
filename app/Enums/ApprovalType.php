<?php

namespace App\Enums;

use App\Enums\Concerns\TranslatableEnum;
use Filament\Support\Contracts\HasLabel;

enum ApprovalType: string implements HasLabel
{
    use TranslatableEnum;

    public const TRANSLATION_KEY = 'approval_type';

    case StockAdjustment = 'stock_adjustment';
    case DamagedStock = 'damaged_stock';
    case Discount = 'discount';
    case PriceOverride = 'price_override';
    case BelowCost = 'below_cost';
    case CreditLimit = 'credit_limit';
    case SaleCancellation = 'sale_cancellation';
    case CreditNote = 'credit_note';
    case ClosedDayEdit = 'closed_day_edit';
    case CashDiscrepancy = 'cash_discrepancy';
}
