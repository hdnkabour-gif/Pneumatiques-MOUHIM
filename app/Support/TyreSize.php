<?php

namespace App\Support;

/**
 * Tyre size formatting and tolerant parsing (§8): "205 55 16", "2055516",
 * "205/55R16", "205/55 R16 91V", "195R15", "215/75 R17.5".
 */
final class TyreSize
{
    public static function format(int $width, ?int $ratio, string $diameter, ?string $load = null, ?string $speed = null): string
    {
        $diameter = rtrim(rtrim($diameter, '0'), '.');
        $size = $ratio ? "{$width}/{$ratio} R{$diameter}" : "{$width} R{$diameter}";
        $index = trim(($load ?? '').($speed ?? ''));

        return $index === '' ? $size : "{$size} {$index}";
    }

    /**
     * @return array{width: int, aspect_ratio: ?int, rim_diameter: string, load_index: ?string, speed_index: ?string}|null
     */
    public static function parse(string $input): ?array
    {
        $s = strtoupper(trim($input));
        $s = preg_replace('/\s+/', ' ', $s);

        // 205/55 R16 91V, 205 55 16, 205-55-16, 205/55ZR16, 215/75R17.5
        $pattern = '~^(\d{3})\s*[/\s\-]\s*(\d{2})\s*(?:[RZ]{1,2}|\s|-)?\s*(\d{2}(?:[.,]5)?)(?:\s*(\d{2,3}(?:/\d{2,3})?)\s*([A-Z]{1,2})?)?$~';
        if (preg_match($pattern, $s, $m)) {
            return self::result($m[1], $m[2], $m[3], $m[4] ?? null, $m[5] ?? null);
        }

        // 2055516 / 20555R16 (compact)
        if (preg_match('~^(\d{3})(\d{2})R?(\d{2})$~', $s, $m)) {
            return self::result($m[1], $m[2], $m[3], null, null);
        }

        // 195R15, 195 R15 (no aspect ratio)
        if (preg_match('~^(\d{3})\s*R\s*(\d{2}(?:[.,]5)?)$~', $s, $m)) {
            return self::result($m[1], null, $m[2], null, null);
        }

        return null;
    }

    private static function result(string $width, ?string $ratio, string $diameter, ?string $load, ?string $speed): ?array
    {
        $w = (int) $width;
        $d = str_replace(',', '.', $diameter);

        if ($w < 100 || $w > 455 || (float) $d < 8 || (float) $d > 26) {
            return null;
        }

        return [
            'width' => $w,
            'aspect_ratio' => $ratio === null ? null : (int) $ratio,
            'rim_diameter' => number_format((float) $d, 1, '.', ''),
            'load_index' => $load ?: null,
            'speed_index' => $speed ?: null,
        ];
    }
}
