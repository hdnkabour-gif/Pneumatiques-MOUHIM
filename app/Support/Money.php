<?php

namespace App\Support;

/** Display of integer cents as MAD, without floats. */
final class Money
{
    public static function format(?int $cents, bool $withCurrency = true): string
    {
        if ($cents === null) {
            return '—';
        }

        [$int, $dec] = explode('.', Decimal::fromCents($cents));
        $negative = str_starts_with($int, '-');
        $int = ltrim($int, '-');
        // Narrow no-break space every 3 digits (byte-safe: never reverse a UTF-8 string).
        $grouped = preg_replace('/\B(?=(\d{3})+(?!\d))/', "\u{202F}", $int);

        return ($negative ? '-' : '').$grouped.','.$dec.($withCurrency ? ' MAD' : '');
    }
}
