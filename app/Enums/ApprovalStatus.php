<?php

namespace App\Enums;

use App\Enums\Concerns\TranslatableEnum;
use Filament\Support\Contracts\HasLabel;

enum ApprovalStatus: string implements HasLabel
{
    use TranslatableEnum;

    public const TRANSLATION_KEY = 'approval_status';

    case Pending = 'pending';
    case Approved = 'approved';
    case Rejected = 'rejected';
    case Cancelled = 'cancelled';
}
