<?php

namespace App\Filament\Resources\ReplenishmentRequests\Pages;

use App\Actions\Transfers\ConvertReplenishmentRequestAction;
use App\Enums\Permission;
use App\Enums\ReplenishmentStatus;
use App\Exceptions\BusinessRuleException;
use App\Filament\Resources\ReplenishmentRequests\ReplenishmentRequestResource;
use App\Filament\Resources\Transfers\TransferResource;
use App\Models\ReplenishmentRequest;
use App\Services\Stock\StockService;
use Filament\Actions\Action;
use Filament\Forms\Components\TextInput;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\ViewRecord;
use Filament\Support\Icons\Heroicon;

class ViewReplenishmentRequest extends ViewRecord
{
    protected static string $resource = ReplenishmentRequestResource::class;

    protected function getHeaderActions(): array
    {
        /** @var ReplenishmentRequest $request */
        $request = $this->record;
        $user = auth()->user();
        $pending = $request->status === ReplenishmentStatus::Pending;

        return [
            Action::make('convert')->label(__('transfers.actions.convert'))->icon(Heroicon::OutlinedTruck)->color('success')
                ->visible($pending && $user->can(Permission::TransfersSend->value) && $user->canAccessSite($request->source_site_id))
                ->schema(fn () => $request->lines()->with('product')->get()->map(fn ($line) => TextInput::make("quantities.{$line->product_id}")
                    ->label($line->product->name)
                    ->helperText(__('transfers.fields.on_hand').' ('.$request->sourceSite->code.') : '.app(StockService::class)->quantityAt($line->product, $request->sourceSite))
                    ->integer()->minValue(0)->default($line->quantity)->required())->all())
                ->action(function (array $data) use ($request) {
                    try {
                        $transfer = app(ConvertReplenishmentRequestAction::class)->execute($request, auth()->user(), array_map('intval', $data['quantities'] ?? []));
                        Notification::make()->success()->title(__('transfers.messages.sent', ['number' => $transfer->number, 'site' => $transfer->fromSite->name]))->send();
                        $this->redirect(TransferResource::getUrl('view', ['record' => $transfer]));
                    } catch (BusinessRuleException $e) {
                        Notification::make()->danger()->title($e->getMessage())->send();
                    }
                }),
            Action::make('cancelRequest')->label(__('transfers.actions.cancel_request'))->color('danger')->requiresConfirmation()
                ->visible($pending && ($user->canAccessSite($request->site_id) || $user->canAccessSite($request->source_site_id)))
                ->action(function () use ($request) {
                    $request->update(['status' => ReplenishmentStatus::Cancelled]);
                    activity('transfers')->causedBy(auth()->user())->performedOn($request)->event('replenishment_cancelled')
                        ->tap(fn ($a) => $a->site_id = $request->site_id)->log('replenishment_cancelled');
                    $this->redirect(ReplenishmentRequestResource::getUrl('view', ['record' => $request]));
                }),
        ];
    }
}
