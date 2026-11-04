<?php

namespace App\Support;

use InvalidArgumentException;

/**
 * Exact conversion between user-entered decimals ("12,50", "0.25") and the
 * integers stored in the database (cents, basis points). Never uses floats.
 */
final class Decimal
{
    /** "12,50" → 1250 (2 decimals). */
    public static function toCents(string|int|null $value): int
    {
        return self::toScaledInt($value, 2);
    }

    /** "20" → 2000, "0,25" → 25 (percent → basis points). */
    public static function percentToBasisPoints(string|int|null $value): int
    {
        return self::toScaledInt($value, 2);
    }

    /** 1250 → "12.50" */
    public static function fromCents(int $cents): string
    {
        return self::fromScaledInt($cents, 2);
    }

    /** 2000 → "20.00" */
    public static function basisPointsToPercent(int $basisPoints): string
    {
        return self::fromScaledInt($basisPoints, 2);
    }

    private static function toScaledInt(string|int|null $value, int $scale): int
    {
        if ($value === null || $value === '') {
            return 0;
        }

        if (is_int($value)) {
            return $value * (10 ** $scale);
        }

        $normalized = str_replace([' ', "\u{00A0}", ','], ['', '', '.'], trim($value));

        if (! preg_match('/^(-?)(\d*)(?:\.(\d*))?$/', $normalized, $m) || ($m[2] === '' && ($m[3] ?? '') === '')) {
            throw new InvalidArgumentException("Invalid decimal value [{$value}].");
        }

        $fraction = $m[3] ?? '';
        if (strlen(rtrim($fraction, '0')) > $scale) {
            throw new InvalidArgumentException("Too many decimals in [{$value}].");
        }

        $int = (int) (($m[2] === '' ? '0' : $m[2]).str_pad(substr($fraction, 0, $scale), $scale, '0'));

        return $m[1] === '-' ? -$int : $int;
    }

    private static function fromScaledInt(int $value, int $scale): string
    {
        $sign = $value < 0 ? '-' : '';
        $digits = str_pad((string) abs($value), $scale + 1, '0', STR_PAD_LEFT);

        return $sign.substr($digits, 0, -$scale).'.'.substr($digits, -$scale);
    }
}
