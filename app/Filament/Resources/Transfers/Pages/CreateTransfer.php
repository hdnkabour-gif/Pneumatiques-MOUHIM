<?php

namespace App\Filament\Resources\Transfers\Pages;

use App\Actions\Transfers\SendTransferAction;
use App\Exceptions\BusinessRuleException;
use App\Filament\Resources\Transfers\TransferResource;
use App\Models\Site;
use Filament\Resources\Pages\CreateRecord;
use Illuminate\Database\Eloquent\Model;

class CreateTransfer extends CreateRecord
{
    protected static string $resource = TransferResource::class;

    protected static bool $canCreateAnother = false;

    protected function handleRecordCreation(array $data): Model
    {
        $lines = collect($data['lines'] ?? [])->mapWithKeys(fn ($l) => [(int) $l['product_id'] => (int) $l['quantity']])->all();

        try {
            return app(SendTransferAction::class)->execute(
                Site::query()->findOrFail($data['from_site_id']),
                Site::query()->findOrFail($data['to_site_id']),
                $lines,
                auth()->user(),
                $data['notes'] ?? null,
            );
        } catch (BusinessRuleException $e) {
            throw $e->toValidationException('data.lines');
        }
    }

    protected function getCreatedNotificationTitle(): ?string
    {
        return __('transfers.messages.sent', ['number' => $this->record->number, 'site' => $this->record->fromSite->name]);
    }

    protected function getRedirectUrl(): string
    {
        return TransferResource::getUrl('view', ['record' => $this->record]);
    }
}
