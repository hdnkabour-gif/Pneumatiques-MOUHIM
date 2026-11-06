<?php

namespace App\Enums;

use App\Enums\Concerns\TranslatableEnum;
use Filament\Support\Contracts\HasLabel;

/**
 * Numbered documents (D-14, D-49). The prefix is a setting the owner can
 * change; the counter key stays tied to the type so changing a prefix never
 * restarts nor duplicates a series.
 */
enum DocumentType: string implements HasLabel
{
    use TranslatableEnum;

    public const TRANSLATION_KEY = 'document_type';

    case Invoice = 'invoice';
    case Quote = 'quote';
    case DeliveryNote = 'delivery_note';
    case WorkNote = 'work_note';
    case CreditNote = 'credit_note';
    case Receipt = 'receipt';
    case WorkOrder = 'work_order';
    case Transfer = 'transfer';
    case Replenishment = 'replenishment';
    case Inventory = 'inventory';

    public function defaultPrefix(): string
    {
        return match ($this) {
            self::Invoice => 'FA',
            self::Quote => 'DV',
            self::DeliveryNote => 'BL',
            self::WorkNote => 'BT',
            self::CreditNote => 'AV',
            self::Receipt => 'RC',
            self::WorkOrder => 'OT',
            self::Transfer => 'TR',
            self::Replenishment => 'DR',
            self::Inventory => 'INV',
        };
    }

    /** Counter key, independent of the configurable prefix (kept compatible with phase 2 keys). */
    public function sequenceKey(int $year): string
    {
        return 'doc:'.$this->defaultPrefix().':'.$year;
    }

    public function prefixSetting(): string
    {
        return 'documents.prefix.'.$this->value;
    }
}
