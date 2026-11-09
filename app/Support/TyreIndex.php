<?php

namespace App\Support;

/**
 * Load and speed indices of passenger / light-truck tyres, from the
 * standard tables (ETRTO, ISO 4000-1 / ISO 4223-1): what "91V" means.
 */
final class TyreIndex
{
    /** Load index → maximum load per tyre in kg. */
    public const LOAD = [
        60 => 250, 61 => 257, 62 => 265, 63 => 272, 64 => 280, 65 => 290, 66 => 300, 67 => 307, 68 => 315, 69 => 325,
        70 => 335, 71 => 345, 72 => 355, 73 => 365, 74 => 375, 75 => 387, 76 => 400, 77 => 412, 78 => 425, 79 => 437,
        80 => 450, 81 => 462, 82 => 475, 83 => 487, 84 => 500, 85 => 515, 86 => 530, 87 => 545, 88 => 560, 89 => 580,
        90 => 600, 91 => 615, 92 => 630, 93 => 650, 94 => 670, 95 => 690, 96 => 710, 97 => 730, 98 => 750, 99 => 775,
        100 => 800, 101 => 825, 102 => 850, 103 => 875, 104 => 900, 105 => 925, 106 => 950, 107 => 975, 108 => 1000, 109 => 1030,
        110 => 1060, 111 => 1090, 112 => 1120, 113 => 1150, 114 => 1180, 115 => 1215, 116 => 1250, 117 => 1285, 118 => 1320, 119 => 1360,
        120 => 1400, 121 => 1450, 122 => 1500, 123 => 1550, 124 => 1600, 125 => 1650, 126 => 1700,
    ];

    /** Speed symbol → maximum speed in km/h. */
    public const SPEED = [
        'J' => 100, 'K' => 110, 'L' => 120, 'M' => 130, 'N' => 140, 'P' => 150, 'Q' => 160, 'R' => 170,
        'S' => 180, 'T' => 190, 'U' => 200, 'H' => 210, 'V' => 240, 'W' => 270, 'Y' => 300,
    ];

    public static function loadKg(?string $index): ?int
    {
        // "102/100" (single / dual fitting): the first value is the single-tyre load.
        $first = (int) explode('/', (string) $index)[0];

        return self::LOAD[$first] ?? null;
    }

    public static function speedKmh(?string $symbol): ?int
    {
        return self::SPEED[strtoupper(trim((string) $symbol))] ?? null;
    }

    /** "615 kg · 240 km/h" for 91 V, empty when unknown (never guessed). */
    public static function describe(?string $load, ?string $speed): string
    {
        return implode(' · ', array_filter([
            ($kg = self::loadKg($load)) ? $kg.' kg' : null,
            ($kmh = self::speedKmh($speed)) ? $kmh.' km/h' : null,
        ]));
    }
}
