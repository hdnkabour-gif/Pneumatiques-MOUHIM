<?php

namespace App\Enums;

use App\Enums\Concerns\TranslatableEnum;
use Filament\Support\Contracts\HasLabel;

/** §7.3: only "verified" is ever presented as compatible. */
enum CompatibilityStatus: string implements HasLabel
{
    use TranslatableEnum;

    public const TRANSLATION_KEY = 'compatibility_status';

    case Verified = 'verified';
    case Unverified = 'unverified';
    case ToCheck = 'to_check';
    case Obsolete = 'obsolete';

    public function color(): string
    {
        return match ($this) {
            self::Verified => 'success',
            self::ToCheck => 'warning',
            self::Unverified => 'gray',
            self::Obsolete => 'danger',
        };
    }

    /**
     * Workbook status → application status (sheet GUIDE_APP). Nothing is
     * imported as verified: verification is an explicit, journalled action.
     */
    public static function fromWorkbook(?string $status): self
    {
        $s = mb_strtoupper(trim((string) $status));

        return match (true) {
            str_contains($s, 'OBSOL') => self::Obsolete,
            str_contains($s, 'OFFICIAL') => self::ToCheck,
            default => self::Unverified,
        };
    }
}
