<?php

namespace App\Filament\Resources\UsedTyres\Pages;

use App\Actions\UsedTyres\UsedTyreActions;
use App\Enums\UsedTyreOrigin;
use App\Exceptions\BusinessRuleException;
use App\Filament\Resources\UsedTyres\UsedTyreResource;
use App\Models\Site;
use Filament\Resources\Pages\CreateRecord;
use Illuminate\Database\Eloquent\Model;

class CreateUsedTyre extends CreateRecord
{
    protected static string $resource = UsedTyreResource::class;

    protected function handleRecordCreation(array $data): Model
    {
        $site = Site::query()->findOrFail($data['site_id']);
        $origin = $data['origin'] instanceof UsedTyreOrigin ? $data['origin'] : UsedTyreOrigin::from($data['origin']);
        unset($data['site_id'], $data['origin']);

        try {
            return app(UsedTyreActions::class)->register($data, $site, $origin, auth()->user());
        } catch (BusinessRuleException $e) {
            throw $e->toValidationException('data.site_id');
        }
    }

    protected function getCreatedNotificationTitle(): ?string
    {
        return __('used_tyres.messages.registered', ['code' => $this->record->code]);
    }

    protected function getRedirectUrl(): string
    {
        return UsedTyreResource::getUrl('view', ['record' => $this->record]);
    }
}
