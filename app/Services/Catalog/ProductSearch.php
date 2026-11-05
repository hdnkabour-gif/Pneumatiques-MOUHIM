<?php

namespace App\Services\Catalog;

use App\Support\TyreSize;
use Illuminate\Database\Eloquent\Builder;

/**
 * Tolerant product search (§8): tyre size in any form, SKU, name,
 * manufacturer or alternative reference, oil viscosity, bulb base.
 */
class ProductSearch
{
    public static function apply(Builder $query, string $search): Builder
    {
        $search = trim($search);
        if ($search === '') {
            return $query;
        }

        if ($size = TyreSize::parse($search)) {
            return $query->whereHas('tyreSpec', function (Builder $q) use ($size) {
                $q->where('width', $size['width'])
                    ->where('rim_diameter', $size['rim_diameter'])
                    ->when($size['aspect_ratio'] !== null, fn ($q) => $q->where('aspect_ratio', $size['aspect_ratio']), fn ($q) => $q->whereNull('aspect_ratio'))
                    ->when($size['load_index'], fn ($q, $v) => $q->where('load_index', $v))
                    ->when($size['speed_index'], fn ($q, $v) => $q->where('speed_index', $v));
            });
        }

        $like = '%'.str_replace(['%', '_'], ['\%', '\_'], mb_strtolower($search)).'%';
        $compact = preg_replace('/[\s\-]/', '', mb_strtolower($search));

        return $query->where(function (Builder $q) use ($like, $compact) {
            $q->whereRaw('lower(products.name) like ?', [$like])
                ->orWhereRaw('lower(products.sku) like ?', [$like])
                ->orWhereRaw("replace(replace(lower(coalesce(products.manufacturer_ref, '')), ' ', ''), '-', '') like ?", ['%'.$compact.'%'])
                ->orWhereHas('references', fn (Builder $r) => $r->whereRaw("replace(replace(lower(reference), ' ', ''), '-', '') like ?", ['%'.$compact.'%']))
                ->orWhereHas('oilSpec', fn (Builder $o) => $o->whereRaw('lower(viscosity) = ?', [$compact]))
                ->orWhereHas('lightingSpec', fn (Builder $l) => $l->whereRaw('lower(base) = ?', [$compact]))
                ->orWhereHas('brand', fn (Builder $b) => $b->whereRaw('lower(name) like ?', [$like]));
        });
    }
}
