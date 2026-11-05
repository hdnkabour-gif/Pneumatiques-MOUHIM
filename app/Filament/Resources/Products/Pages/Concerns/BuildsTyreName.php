<?php

namespace App\Filament\Resources\Products\Pages\Concerns;

use App\Enums\CategoryType;
use App\Enums\ProductKind;
use App\Models\Brand;
use App\Models\Category;
use App\Support\TyreSize;

/** New tyre without a name: "Michelin Primacy 4 205/55 R16 91V" (§5.2: name or size). */
trait BuildsTyreName
{
    protected function withProductDefaults(array $data): array
    {
        $data['kind'] = ProductKind::Goods;
        $spec = $this->data['tyreSpec'] ?? [];

        if (blank($data['name'] ?? null) && Category::query()->whereKey($data['category_id'])->value('type') === CategoryType::NewTyre && filled($spec['width'] ?? null)) {
            $data['name'] = trim(implode(' ', array_filter([
                Brand::query()->whereKey($data['brand_id'] ?? null)->value('name'),
                $spec['pattern'] ?? null,
                TyreSize::format((int) $spec['width'], filled($spec['aspect_ratio'] ?? null) ? (int) $spec['aspect_ratio'] : null, (string) $spec['rim_diameter'], $spec['load_index'] ?? null, isset($spec['speed_index']) ? strtoupper($spec['speed_index']) : null),
            ])));
        }

        return $data;
    }
}
