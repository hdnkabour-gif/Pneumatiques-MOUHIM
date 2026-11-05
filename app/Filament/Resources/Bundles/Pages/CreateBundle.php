<?php

namespace App\Filament\Resources\Bundles\Pages;

use App\Enums\ProductKind;
use App\Filament\Resources\Bundles\BundleResource;
use Filament\Resources\Pages\CreateRecord;

class CreateBundle extends CreateRecord
{
    protected static string $resource = BundleResource::class;

    protected function mutateFormDataBeforeCreate(array $data): array
    {
        return [...$data, 'kind' => ProductKind::Bundle, 'category_id' => BundleResource::bundleCategoryId()];
    }
}
