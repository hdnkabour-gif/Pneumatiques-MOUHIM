<?php

namespace App\Services\Catalog;

use App\Models\Product;

/**
 * Decomposes a bundle into its products and services with the allocated
 * prices (§5.9), so invoices and stock movements use the real items.
 */
class BundleExpander
{
    /** @return list<array{product: Product, quantity: int, unit_price_cents: int}> */
    public function expand(Product $bundle, int $quantity = 1): array
    {
        return $bundle->bundleItems()->with('product')->get()
            ->map(fn ($item) => [
                'product' => $item->product,
                'quantity' => $item->quantity * $quantity,
                'unit_price_cents' => $item->unit_price_cents,
            ])->all();
    }

    /** @param  iterable<array{quantity: int|string, unit_price_cents: int|string|null}>  $items */
    public static function total(iterable $items): int
    {
        $sum = 0;
        foreach ($items as $item) {
            $sum += (int) $item['quantity'] * (int) $item['unit_price_cents'];
        }

        return $sum;
    }
}
