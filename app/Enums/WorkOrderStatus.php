<?php

namespace App\Enums;

use App\Enums\Concerns\TranslatableEnum;
use Filament\Support\Contracts\HasLabel;

enum WorkOrderStatus: string implements HasLabel
{
    use TranslatableEnum;

    public const TRANSLATION_KEY = 'work_order_status';

    case Pending = 'pending';
    case InProgress = 'in_progress';
    case Paused = 'paused';
    case Done = 'done';
    case Delivered = 'delivered';
    case Cancelled = 'cancelled';

    public function color(): string
    {
        return match ($this) {
            self::Pending => 'gray',
            self::InProgress => 'warning',
            self::Paused => 'info',
            self::Done => 'success',
            self::Delivered => 'primary',
            self::Cancelled => 'danger',
        };
    }
}
