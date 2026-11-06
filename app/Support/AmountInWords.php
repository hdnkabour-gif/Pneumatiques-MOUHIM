<?php

namespace App\Support;

use NumberFormatter;

/** "Arrêtée la présente facture à la somme de…" (§10.4): amount in French words. */
final class AmountInWords
{
    public static function french(int $cents): string
    {
        $f = new NumberFormatter('fr', NumberFormatter::SPELLOUT);
        $dirhams = intdiv(abs($cents), 100);
        $centimes = abs($cents) % 100;

        $text = $f->format($dirhams).' '.($dirhams > 1 ? 'dirhams' : 'dirham');
        if ($centimes > 0) {
            $text .= ' et '.$f->format($centimes).' '.($centimes > 1 ? 'centimes' : 'centime');
        }

        return mb_strtoupper(mb_substr($text, 0, 1)).mb_substr($text, 1);
    }
}
