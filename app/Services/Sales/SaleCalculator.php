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

            if (! empty($item['trade_in'])) {
                $rows[] = $this->tradeInLine((string) $item['trade_in'], (int) ($item['value_cents'] ?? 0), (array) ($item['meta'] ?? []));

                continue;
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
        if ($ttc < 0) { // trade-in lines: same rounding as the positive amount
            $positive = self::split(-$ttc, $vatBp);

            return ['ht' => -$positive['ht'], 'vat' => -$positive['vat'], 'ttc' => $ttc];
        }

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
            'trade_in_type' => null,
            'meta' => null,
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
            'trade_in_type' => null,
            'meta' => null,
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

    /**
     * Trade-in (§10.1 step 4, D-52): tyre or battery left by the customer,
     * deducted from the total as a negative line. The value is the seller's
     * choice, capped by a setting (above it the owner approves).
     */
    private function tradeInLine(string $type, int $value, array $meta): array
    {
        if (! in_array($type, ['tyre', 'battery'], true) || $value <= 0) {
            throw BusinessRuleException::make('sales.errors.trade_in_value');
        }

        $size = $type === 'tyre' && filled($meta['size'] ?? null) ? TyreSize::parse((string) $meta['size']) : null;
        if ($type === 'tyre' && ! $size) {
            throw BusinessRuleException::make('customers.errors.tyre_size');
        }

        $vat = $this->settings->get('tax.trade_in_vat_enabled') ? $this->settings->vatRateBasisPoints() : 0;
        $totals = self::split(-$value, $vat);
        $label = $type === 'tyre'
            ? __('sales.lines.trade_in_tyre', ['size' => TyreSize::format($size['width'], $size['aspect_ratio'], $size['rim_diameter'])], 'fr')
            : __('sales.lines.trade_in_battery', [], 'fr');

        return [
            'product_id' => null,
            'used_tyre_id' => null,
            'bundle_id' => null,
            'kind' => 'trade_in',
            'trade_in_type' => $type,
            'meta' => $size ? ['width' => $size['width'], 'aspect_ratio' => $size['aspect_ratio'], 'rim_diameter' => $size['rim_diameter'],
                'tread_depth_tenths' => isset($meta['tread_mm']) && is_numeric(str_replace(',', '.', (string) $meta['tread_mm'])) ? (int) round(((float) str_replace(',', '.', (string) $meta['tread_mm'])) * 10) : null,
                ...$this->dot($meta['dot'] ?? null)] : null,
            'description' => $label,
            'quantity' => 1,
            'list_price_ttc_cents' => -$value,
            'unit_price_ttc_cents' => -$value,
            'discount_bp' => 0,
            'vat_rate_bp' => $vat,
            'total_ht_cents' => $totals['ht'],
            'total_vat_cents' => $totals['vat'],
            'total_ttc_cents' => $totals['ttc'],
        ];
    }

    /** DOT "2423" = week 24 of 2023; anything else is left empty (never invented). */
    private function dot(mixed $value): array
    {
        $digits = preg_replace('/\D/', '', (string) $value);
        if (strlen($digits) !== 4 || (int) substr($digits, 0, 2) < 1 || (int) substr($digits, 0, 2) > 53) {
            return [];
        }
        $year = 2000 + (int) substr($digits, 2, 2);

        return $year > (int) date('Y') ? [] : ['dot_week' => (int) substr($digits, 0, 2), 'dot_year' => $year];
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
