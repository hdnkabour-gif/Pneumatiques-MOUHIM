<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;

/**
 * The company's paper invoices are numbered "219/26" (number / two-digit year,
 * no letters). Other documents keep their letters (DV219/26, AV12/26...) so two
 * documents can never share a number. Only applied while the owner has not
 * changed the numbering format (D-58), on the installation already in use.
 */
return new class extends Migration
{
    public function up(): void
    {
        $get = fn (string $key) => ($v = DB::table('settings')->where('key', $key)->value('value')) === null ? null : json_decode($v, true);
        $pattern = $get('documents.number_pattern');
        if (! DB::table('users')->exists()) {
            return; // fresh installation (tests, new client): generic default format
        }
        if ($pattern !== null && $pattern !== '{PREFIX}-{YEAR}-{NUMBER}') {
            return;
        }
        $put = fn (string $key, mixed $value) => DB::table('settings')->updateOrInsert(['key' => $key], ['value' => json_encode($value), 'updated_at' => now(), 'created_at' => now()]);
        $put('documents.number_pattern', '{PREFIX}{NUMBER}/{YY}');
        $put('documents.number_padding', 1);
        if (in_array($get('documents.prefix.invoice'), [null, 'FA'], true)) {
            $put('documents.prefix.invoice', '');
        }
        Cache::forget('mouhim.settings');
    }

    public function down(): void
    {
        // Numbering choice stays: nothing to undo.
    }
};
