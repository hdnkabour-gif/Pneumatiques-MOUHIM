<?php

namespace App\Services\Stock;

use Illuminate\Support\Facades\DB;

/**
 * Compares the cached quantities with the ledger (D-08): stock_levels and
 * tyre_lots must equal the sum of their stock_movements.
 */
class StockVerifier
{
    /** @return list<array{kind: string, product_id: int, site_id: int, lot_id: ?int, cached: int, ledger: int}> */
    public function differences(): array
    {
        $levels = DB::select(<<<'SQL'
            SELECT COALESCE(l.product_id, m.product_id) AS product_id, COALESCE(l.site_id, m.site_id) AS site_id,
                   COALESCE(l.quantity, 0) AS cached, COALESCE(m.total, 0) AS ledger
            FROM stock_levels l
            FULL OUTER JOIN (
                SELECT product_id, site_id, SUM(quantity) AS total FROM stock_movements
                WHERE product_id IS NOT NULL GROUP BY product_id, site_id
            ) m ON m.product_id = l.product_id AND m.site_id = l.site_id
            WHERE COALESCE(l.quantity, 0) <> COALESCE(m.total, 0)
        SQL);

        $lots = DB::select(<<<'SQL'
            SELECT t.product_id, t.site_id, t.id AS lot_id, t.quantity AS cached, COALESCE(SUM(m.quantity), 0) AS ledger
            FROM tyre_lots t LEFT JOIN stock_movements m ON m.tyre_lot_id = t.id
            GROUP BY t.id HAVING t.quantity <> COALESCE(SUM(m.quantity), 0)
        SQL);

        $rows = [];
        foreach ($levels as $r) {
            $rows[] = ['kind' => 'level', 'product_id' => (int) $r->product_id, 'site_id' => (int) $r->site_id, 'lot_id' => null, 'cached' => (int) $r->cached, 'ledger' => (int) $r->ledger];
        }
        foreach ($lots as $r) {
            $rows[] = ['kind' => 'lot', 'product_id' => (int) $r->product_id, 'site_id' => (int) $r->site_id, 'lot_id' => (int) $r->lot_id, 'cached' => (int) $r->cached, 'ledger' => (int) $r->ledger];
        }

        return $rows;
    }
}
