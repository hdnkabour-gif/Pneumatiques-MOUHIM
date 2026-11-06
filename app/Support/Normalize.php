<?php

namespace App\Support;

/** Search keys for phones and plates (§7.2, §8): tolerant to spaces, dashes and formats. */
final class Normalize
{
    /** "06 61-23 45 67", "+212 661234567", "00212661234567" → "212661234567" */
    public static function phone(?string $phone): ?string
    {
        $digits = preg_replace('/\D+/', '', (string) $phone);
        if ($digits === '') {
            return null;
        }
        if (str_starts_with($digits, '00')) {
            $digits = substr($digits, 2);
        }
        if (strlen($digits) === 10 && $digits[0] === '0') {
            $digits = '212'.substr($digits, 1);
        }

        return $digits;
    }

    /** "12345-أ-6", "12345 | أ | 6", "ww 123456" → "12345أ6", "WW123456" (Arabic letters kept). */
    public static function plate(?string $plate): ?string
    {
        $key = mb_strtoupper(preg_replace('/[^\p{L}\p{N}]+/u', '', (string) $plate));

        return $key === '' ? null : $key;
    }

    /** wa.me link (D-26): Moroccan numbers without "+"; null when the number is unusable. */
    public static function whatsappUrl(?string $phone, string $message): ?string
    {
        $number = self::phone($phone);
        if ($number === null || strlen($number) < 9) {
            return null;
        }

        return 'https://wa.me/'.$number.'?text='.rawurlencode($message);
    }
}
