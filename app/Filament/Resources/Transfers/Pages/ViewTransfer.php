<?php

namespace App\Filament\Resources\Transfers\Pages;

use App\Actions\Transfers\CancelTransferAction;
use App\Actions\Transfers\ReceiveTransferAction;
use App\Enums\Permission;
use App\Enums\TransferStatus;
use App\Exceptions\BusinessRuleException;
use App\Filament\Resources\Transfers\TransferResource;
use App\Models\Transfer;
use Filament\Actions\Action;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\ViewRecord;
use Filament\Schemas\Components\Grid;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Support\Icons\Heroicon;

class ViewTransfer extends ViewRecord
{
    protected static string $resource = TransferResource::class;

    protected function getHeaderActions(): array
    {
        /** @var Transfer $transfer */
        $transfer = $this->record;
        $user = auth()->user();

        return [
            Action::make('receive')->label(__('transfers.actions.receive'))->icon(Heroicon::OutlinedInboxArrowDown)->color('success')
                ->visible($transfer->status === TransferStatus::InTransit && $user->can(Permission::TransfersReceive->value) && $user->canAccessSite($transfer->to_site_id))
                ->modalWidth('3xl')
                ->schema(fn () => $transfer->lines()->with('product')->get()->map(fn ($line) => Grid::make(3)->schema([
                    TextInput::make("received.{$line->id}")->label($line->product->name)
                        ->helperText(__('transfers.fields.quantity_sent').' : '.$line->quantity_sent)
                        ->integer()->minValue(0)->maxValue($line->quantity_sent)->default($line->quantity_sent)->required()->live(),
                    TextInput::make("reasons.{$line->id}")->label(__('transfers.fields.discrepancy_reason'))->columnSpan(2)
                        ->required(fn (Get $get) => (int) $get("received.{$line->id}") < $line->quantity_sent),
                ]))->all())
                ->action(function (array $data) use ($transfer) {
                    try {
                        app(ReceiveTransferAction::class)->execute($transfer, auth()->user(), $data['received'] ?? [], array_filter($data['reasons'] ?? []));
                        Notification::make()->success()->title(__('transfers.messages.received'))->send();
                        $this->refreshFormData([]);
                        $this->redirect(TransferResource::getUrl('view', ['record' => $transfer]));
                    } catch (BusinessRuleException $e) {
                        Notification::make()->danger()->title($e->getMessage())->send();
                    }
                }),
            Action::make('cancel')->label(__('transfers.actions.cancel'))->icon(Heroicon::OutlinedXCircle)->color('danger')
                ->visible($transfer->status === TransferStatus::InTransit && $user->can(Permission::TransfersSend->value) && $user->canAccessSite($transfer->from_site_id))
                ->requiresConfirmation()
                ->schema([Textarea::make('reason')->label(__('transfers.fields.cancel_reason'))->required()])
                ->action(function (array $data) use ($transfer) {
                    try {
                        app(CancelTransferAction::class)->execute($transfer, auth()->user(), $data['reason']);
                        Notification::make()->success()->title(__('transfers.messages.cancelled'))->send();
                        $this->redirect(TransferResource::getUrl('view', ['record' => $transfer]));
                    } catch (BusinessRuleException $e) {
                        Notification::make()->danger()->title($e->getMessage())->send();
                    }
                }),
            Action::make('print')->label(__('transfers.actions.print'))->icon(Heroicon::OutlinedPrinter)->color('gray')
                ->url(route('transfers.print', $transfer))->openUrlInNewTab(),
        ];
    }
}
