<?php

namespace App\Services\Sales;

use App\Enums\PriceLevel;
use App\Enums\ProductKind;
use App\Enums\UsedTyreStatus;
use App\Exceptions\BusinessRuleException;
use App\Models\CustomerVehicle;
use App\Models\Product;
use App\Models\Site;
use App\Models\UsedTyre;
use App\Services\Catalog\BundleExpander;
use App\Services\SettingsService;
use App\Support\TyreSize;

/**
 * Turns what the seller picked into priced invoice lines. Prices always come
 * from the catalogue (never from the browser); the seller only chooses a
 * discount or, with approval, another price. Amounts are TTC cents, VAT is
 * taken out of the TTC price and copied on every line (D-19).
 */
class SaleCalculator
{
    public function __construct(private SettingsService $settings, private BundleExpander $bundles) {}

    /**
     * @param  list<array{product_id?: int, used_tyre_id?: int, quantity?: int, discount_bp?: int, unit_price_ttc_cents?: int|null}>  $input
     * @return list<array<string, mixed>> rows ready for sale_lines
     */
    public function lines(array $input, Site $site, PriceLevel $level, ?CustomerVehicle $vehicle = null): array
    {
        $vat = $this->settings->vatRateBasisPoints();
        $products = Product::query()->with(['category', 'tyreSpec', 'priceTiers', 'sites'])
            ->whereKey(array_filter(array_column($input, 'product_id')))->get()->keyBy('id');
        $rim = $this->rimDiameter($products->all(), $vehicle);

        $rows = [];
        foreach ($input as $item) {
            $discount = max(0, min(10000, (int) ($item['discount_bp'] ?? 0)));
            $quantity = (int) ($item['quantity'] ?? 1);

            if ($quantity < 1) {
                throw BusinessRuleException::make('sales.errors.quantity');
            }

            if (! empty($item['used_tyre_id'])) {
                $rows[] = $this->usedTyreLine((int) $item['used_tyre_id'], $site, $discount, $item['unit_price_ttc_cents'] ?? null);

                continue;
            }

            $product = $products->get((int) ($item['product_id'] ?? 0));
            if (! $product || ! $product->is_active) {
                throw BusinessRuleException::make('sales.errors.product_unavailable');
            }

            if ($product->kind === ProductKind::Service && $product->sites->isNotEmpty() && ! $product->sites->contains($site)) {
                throw BusinessRuleException::make('sales.errors.service_not_offered', ['service' => $product->name, 'site' => $site->name]);
            }

            if ($product->kind === ProductKind::Bundle) {
                foreach ($this->bundles->expand($product, $quantity) as $part) {
                    $rows[] = $this->row($part['product'], $part['quantity'], $part['unit_price_cents'], $discount, null, $vat, $product->id);
                }

                continue;
            }

            $list = $this->listPrice($product, $level, $rim);
            $rows[] = $this->row($product, $quantity, $list, $discount, $item['unit_price_ttc_cents'] ?? null, $vat);
        }

        foreach ($rows as $i => &$row) {
            $row['sort_order'] = $i;
        }

        return $rows;
    }

    /** Catalogue price for the level; services priced by rim size use the matching tier (§5.8). */
    public function listPrice(Product $product, PriceLevel $level, ?float $rim = null): int
    {
        if ($product->kind === ProductKind::Service && $rim !== null) {
            $tier = $product->priceTiers->first(fn ($t) => $rim >= (float) $t->min_rim_diameter
                && ($t->max_rim_diameter === null || $rim <= (float) $t->max_rim_diameter));
            if ($tier) {
                return $tier->price_cents;
            }
        }

        return (int) $product->priceFor($level->value);
    }

    /** @return array{ht: int, vat: int, ttc: int} */
    public static function split(int $ttc, int $vatBp): array
    {
        $ht = intdiv($ttc * 10000 * 2 + (10000 + $vatBp), (10000 + $vatBp) * 2);

        return ['ht' => $ht, 'vat' => $ttc - $ht, 'ttc' => $ttc];
    }

    private function row(Product $product, int $quantity, int $list, int $discount, ?int $override, int $vat, ?int $bundleId = null): array
    {
        if ($override !== null && $override >= 0 && $override !== $list) {
            $unit = $override;
            $discount = $list > 0 && $override < $list ? intdiv(($list - $override) * 10000 + intdiv($list, 2), $list) : 0;
        } else {
            $unit = intdiv($list * (10000 - $discount) + 5000, 10000);
        }
        $totals = self::split($unit * $quantity, $vat);

        return [
            'product_id' => $product->id,
            'used_tyre_id' => null,
            'bundle_id' => $bundleId,
            'kind' => $product->kind === ProductKind::Service ? 'service' : 'goods',
            'description' => $product->name,
            'quantity' => $quantity,
            'list_price_ttc_cents' => $list,
            'unit_price_ttc_cents' => $unit,
            'discount_bp' => $discount,
            'vat_rate_bp' => $vat,
            'total_ht_cents' => $totals['ht'],
            'total_vat_cents' => $totals['vat'],
            'total_ttc_cents' => $totals['ttc'],
        ];
    }

    private function usedTyreLine(int $id, Site $site, int $discount, ?int $override): array
    {
        $tyre = UsedTyre::query()->with('brand')->find($id);

        // A used tyre that failed the safety check is never sold (CLAUDE.md §4).
        if (! $tyre || $tyre->status !== UsedTyreStatus::InStock || $tyre->site_id !== $site->id || $tyre->price_cents === null) {
            throw BusinessRuleException::make('sales.errors.used_tyre_unavailable', ['code' => $tyre?->code ?? $id]);
        }

        $vat = $this->settings->get('tax.used_tyre_vat_enabled') ? $this->settings->vatRateBasisPoints() : 0;
        $list = (int) $tyre->price_cents;
        $unit = $override !== null && $override >= 0 ? (int) $override : intdiv($list * (10000 - $discount) + 5000, 10000);
        $discount = $list > 0 && $unit < $list ? intdiv(($list - $unit) * 10000 + intdiv($list, 2), $list) : 0;
        $totals = self::split($unit, $vat);

        return [
            'product_id' => null,
            'used_tyre_id' => $tyre->id,
            'bundle_id' => null,
            'kind' => 'used_tyre',
            'description' => trim(__('sales.lines.used_tyre', ['size' => $tyre->sizeLabel(), 'code' => $tyre->code], 'fr').' '.($tyre->brand?->name ?? '')),
            'quantity' => 1,
            'list_price_ttc_cents' => $list,
            'unit_price_ttc_cents' => $unit,
            'discount_bp' => $discount,
            'vat_rate_bp' => $vat,
            'total_ht_cents' => $totals['ht'],
            'total_vat_cents' => $totals['vat'],
            'total_ttc_cents' => $totals['ttc'],
        ];
    }

    /** @param  list<Product>  $products */
    private function rimDiameter(array $products, ?CustomerVehicle $vehicle): ?float
    {
        $rims = array_filter(array_map(fn (Product $p) => $p->tyreSpec?->rim_diameter, $products));
        if ($rims !== []) {
            return (float) max($rims);
        }

        $size = $vehicle?->tyre_size_front ? TyreSize::parse($vehicle->tyre_size_front) : null;

        return $size ? (float) str_replace(',', '.', $size['rim_diameter']) : null;
    }
}
