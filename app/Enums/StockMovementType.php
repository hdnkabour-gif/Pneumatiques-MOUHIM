<?php

namespace App\Enums;

use App\Enums\Concerns\TranslatableEnum;
use Filament\Support\Contracts\HasLabel;

enum StockMovementType: string implements HasLabel
{
    use TranslatableEnum;

    public const TRANSLATION_KEY = 'stock_movement_type';

    case PurchaseReceipt = 'purchase_receipt';
    case OpeningStock = 'opening_stock';
    case Sale = 'sale';
    case CustomerReturn = 'customer_return';
    case CustomerExchange = 'customer_exchange';
    case SupplierReturn = 'supplier_return';
    case TransferOut = 'transfer_out';
    case TransferIn = 'transfer_in';
    case TransferCancelled = 'transfer_cancelled';
    case WorkshopConsumption = 'workshop_consumption';
    case InventoryAdjustment = 'inventory_adjustment';
    case Damaged = 'damaged';
    case TradeIn = 'trade_in';

    /** +1 = always an entry, -1 = always an exit, 0 = either (adjustments, exchanges). */
    public function direction(): int
    {
        return match ($this) {
            self::PurchaseReceipt, self::OpeningStock, self::CustomerReturn, self::TransferIn,
            self::TransferCancelled, self::TradeIn => 1,
            self::Sale, self::SupplierReturn, self::TransferOut, self::WorkshopConsumption, self::Damaged => -1,
            self::InventoryAdjustment, self::CustomerExchange => 0,
        };
    }

    /** Entries that bring a new purchase cost and therefore update the weighted average cost. */
    public function updatesAverageCost(): bool
    {
        return in_array($this, [self::PurchaseReceipt, self::OpeningStock, self::TradeIn], true);
    }
}
