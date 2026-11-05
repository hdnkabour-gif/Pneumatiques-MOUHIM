<?php

namespace App\Services\Stock;

use App\Models\UsedTyre;
use App\Services\SettingsService;
use Illuminate\Support\Collection;

/**
 * Matching sets of used tyres (§5.4, D-43): same size, brand and pattern,
 * tread depths within the configured tolerance. Only sellable tyres.
 */
class UsedTyreSetFinder
{
    /** @return Collection<int, Collection<int, UsedTyre>> */
    public function find(int $setSize = 4, ?int $siteId = null, ?array $size = null): Collection
    {
        $tolerance = (int) app(SettingsService::class)->get('used_tyres.set_tread_tolerance_tenths');

        $tyres = UsedTyre::query()
            ->sellable()
            ->whereNotNull('tread_depth_tenths')
            ->when($siteId, fn ($q) => $q->where('site_id', $siteId))
            ->when($size, fn ($q) => $q->where('width', $size['width'])
                ->where('aspect_ratio', $size['aspect_ratio'])
                ->where('rim_diameter', $size['rim_diameter']))
            ->with('brand')
            ->get();

        $sets = collect();

        $groups = $tyres->groupBy(fn (UsedTyre $t) => implode('|', [
            $t->site_id, $t->width, $t->aspect_ratio, $t->rim_diameter, $t->brand_id, mb_strtolower(trim((string) $t->pattern)),
        ]));

        foreach ($groups as $group) {
            $sorted = $group->sortBy('tread_depth_tenths')->values();
            $i = 0;
            while ($i + $setSize <= $sorted->count()) {
                $candidate = $sorted->slice($i, $setSize)->values();
                if ($candidate->last()->tread_depth_tenths - $candidate->first()->tread_depth_tenths <= $tolerance) {
                    $sets->push($candidate);
                    $i += $setSize;
                } else {
                    $i++;
                }
            }
        }

        return $sets;
    }
}
