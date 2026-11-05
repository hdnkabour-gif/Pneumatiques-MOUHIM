<?php

namespace Database\Seeders;

use App\Models\Brand;
use Illuminate\Database\Seeder;

/**
 * Tyre and lubricant brands from the reference workbook
 * PNEU_MOUHIM_Manager_Catalogue_Operations V1.2 (TIRE_BRANDS, OIL_BRANDS).
 * Names only: no product is created. Safe in production and idempotent;
 * a brand the owner renamed or deactivated is left untouched.
 * "Huiles Lesieur/Local brands" is deliberately excluded (not a lubricant brand).
 */
class ReferenceBrandsSeeder extends Seeder
{
    public const TYRES = [
        'Michelin', 'Bridgestone', 'Continental', 'Goodyear', 'Pirelli', 'Dunlop', 'Hankook', 'Kumho', 'Yokohama',
        'Toyo', 'Falken', 'Nexen', 'Firestone', 'BFGoodrich', 'General Tire', 'Kleber', 'Uniroyal', 'Laufenn',
        'Nankang', 'Maxxis', 'Sailun', 'Linglong', 'Triangle', 'Goodride', 'Westlake', 'Roadstone', 'Tracmax',
        'Milever', 'Rotalla', 'Aplus', 'Atlas', 'Tigar', 'Riken', 'Matador', 'Debica', 'Sava',
    ];

    public const OILS = [
        'TotalEnergies', 'Shell', 'Castrol', 'Mobil', 'Motul', 'Elf', 'Petronas', 'Valvoline', 'Liqui Moly', 'Fuchs',
        'Repsol', 'Mannol', 'Ravenol', 'Q8 Oils', 'Wolf', 'Gulf', 'Eni', 'Aral', 'Kroon-Oil', 'Yacco', 'Cepsa',
    ];

    public function run(): void
    {
        $existing = Brand::query()->pluck('name')->map(fn (string $n) => mb_strtolower(trim($n)))->all();

        foreach ([...self::TYRES, ...self::OILS] as $name) {
            if (! in_array(mb_strtolower($name), $existing, true)) {
                Brand::query()->create(['name' => $name, 'is_active' => true]);
                $existing[] = mb_strtolower($name);
            }
        }
    }
}
