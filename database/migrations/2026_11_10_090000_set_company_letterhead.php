<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;

/**
 * Company identity printed on the letterhead the owner provided (photo of
 * the company paper, 10 Nov 2026). Only fills values that are still empty:
 * anything the owner already typed in the settings page is kept.
 */
return new class extends Migration
{
    private const VALUES = [
        'company.name' => 'PNEUMATIQUE MOUHIM',
        'company.address' => 'Siège social : Rue 69 - N° 44-46 Hay Oulfa',
        'company.city' => 'Casablanca',
        'company.phone' => '05 22 89 74 73 / 06 49 05 63 63',
        'legal.rc' => '238417',
        'legal.if' => '40399081',
        'legal.patente' => '36091194',
        'legal.cnss' => '8789763',
        'legal.ice' => '000167198000034',
    ];

    public function up(): void
    {
        foreach (self::VALUES as $key => $value) {
            $current = DB::table('settings')->where('key', $key)->value('value');
            $decoded = $current === null ? null : json_decode($current, true);
            if ($decoded === null || $decoded === '' || ($key === 'company.name' && $decoded === 'PNEU MOUHIM')) {
                DB::table('settings')->updateOrInsert(['key' => $key], ['value' => json_encode($value), 'updated_at' => now(), 'created_at' => now()]);
            }
        }
        Cache::forget('mouhim.settings');
    }

    public function down(): void
    {
        // Company data stays: nothing to undo.
    }
};
