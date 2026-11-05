<?php

namespace App\Filament\Resources\ReplenishmentRequests\Pages;

use App\Actions\Transfers\CreateReplenishmentRequestAction;
use App\Exceptions\BusinessRuleException;
use App\Filament\Resources\ReplenishmentRequests\ReplenishmentRequestResource;
use App\Models\Site;
use App\Services\Stock\ReplenishmentSuggester;
use Filament\Actions\Action;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\CreateRecord;
use Filament\Support\Icons\Heroicon;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Str;

class CreateReplenishmentRequest extends CreateRecord
{
    protected static string $resource = ReplenishmentRequestResource::class;

    protected static bool $canCreateAnother = false;

    protected function getHeaderActions(): array
    {
        return [
            Action::make('suggest')->label(__('transfers.actions.suggest'))->icon(Heroicon::OutlinedSparkles)->color('gray')
                ->action(function () {
                    $site = Site::query()->find($this->data['site_id'] ?? null);
                    $source = Site::query()->find($this->data['source_site_id'] ?? null);
                    $suggestions = $site && $source ? app(ReplenishmentSuggester::class)->forSite($site, $source) : collect();

                    if ($suggestions->isEmpty()) {
                        Notification::make()->warning()->title(__('transfers.messages.no_suggestion'))->send();

                        return;
                    }

                    $this->data['lines'] = $suggestions->mapWithKeys(fn ($s) => [(string) Str::uuid() => [
                        'product_id' => $s->product_id, 'quantity' => $s->suggested,
                    ]])->all();
                }),
        ];
    }

    protected function handleRecordCreation(array $data): Model
    {
        $lines = collect($data['lines'] ?? [])->mapWithKeys(fn ($l) => [(int) $l['product_id'] => (int) $l['quantity']])->all();

        try {
            return app(CreateReplenishmentRequestAction::class)->execute(
                Site::query()->findOrFail($data['site_id']),
                Site::query()->findOrFail($data['source_site_id']),
                $lines,
                auth()->user(),
                $data['notes'] ?? null,
            );
        } catch (BusinessRuleException $e) {
            throw $e->toValidationException('data.lines');
        }
    }

    protected function getRedirectUrl(): string
    {
        return ReplenishmentRequestResource::getUrl('view', ['record' => $this->record]);
    }
}
