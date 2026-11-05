<?php

namespace App\Filament\Resources\InventoryCounts\Pages;

use App\Actions\Inventory\InventoryCountActions;
use App\Exceptions\BusinessRuleException;
use App\Filament\Resources\InventoryCounts\InventoryCountResource;
use App\Models\Category;
use App\Models\Site;
use Filament\Resources\Pages\CreateRecord;
use Illuminate\Database\Eloquent\Model;

class CreateInventoryCount extends CreateRecord
{
    protected static string $resource = InventoryCountResource::class;

    protected static bool $canCreateAnother = false;

    protected function handleRecordCreation(array $data): Model
    {
        try {
            return app(InventoryCountActions::class)->create(
                Site::query()->findOrFail($data['site_id']),
                auth()->user(),
                isset($data['category_id']) ? Category::query()->find($data['category_id']) : null,
                $data['notes'] ?? null,
            );
        } catch (BusinessRuleException $e) {
            throw $e->toValidationException('data.site_id');
        }
    }

    protected function getRedirectUrl(): string
    {
        return InventoryCountResource::getUrl('count', ['record' => $this->record]);
    }
}
