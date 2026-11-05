<?php

namespace App\Services\Stock;

use App\Models\Site;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * Products below their minimum at a store and available at the source site
 * (§6.3). Suggested quantity = shortfall to the minimum, capped by what the
 * source can give.
 */
class ReplenishmentSuggester
{
    /** @return Collection<int, object{product_id: int, sku: string, name: string, on_hand: int, minimum: int, source_available: int, suggested: int}> */
    public function forSite(Site $store, Site $source): Collection
    {
        return collect(DB::select(<<<'SQL'
            SELECT p.id AS product_id, p.sku, p.name,
                   COALESCE(sl.quantity, 0) AS on_hand,
                   t.min_quantity AS minimum,
                   (src.quantity - src.reserved) AS source_available
            FROM product_site_thresholds t
            JOIN products p ON p.id = t.product_id AND p.is_active
            LEFT JOIN stock_levels sl ON sl.product_id = t.product_id AND sl.site_id = t.site_id
            JOIN stock_levels src ON src.product_id = t.product_id AND src.site_id = ?
            WHERE t.site_id = ?
              AND COALESCE(sl.quantity, 0) < t.min_quantity
              AND src.quantity - src.reserved > 0
            ORDER BY p.name
        SQL, [$source->id, $store->id]))->map(function ($row) {
            $row->on_hand = (int) $row->on_hand;
            $row->minimum = (int) $row->minimum;
            $row->source_available = (int) $row->source_available;
            $row->suggested = min($row->minimum - $row->on_hand, $row->source_available);

            return $row;
        });
    }
}
