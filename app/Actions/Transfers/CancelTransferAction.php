<?php

namespace App\Actions\Transfers;

use App\Enums\Permission;
use App\Enums\StockMovementType;
use App\Enums\TransferStatus;
use App\Exceptions\BusinessRuleException;
use App\Models\Transfer;
use App\Models\User;
use App\Services\Stock\StockService;
use Illuminate\Support\Facades\DB;

/** Cancels a transfer still in transit: the goods go back to the source site. */
class CancelTransferAction
{
    public function __construct(private StockService $stock) {}

    public function execute(Transfer $transfer, User $user, string $reason): Transfer
    {
        if (! $user->can(Permission::TransfersSend->value) || ! $user->canAccessSite($transfer->from_site_id)) {
            throw BusinessRuleException::make('app.errors.forbidden');
        }

        if (trim($reason) === '') {
            throw BusinessRuleException::make('transfers.errors.cancel_reason_required');
        }

        return DB::transaction(function () use ($transfer, $user, $reason) {
            $transfer = Transfer::query()->lockForUpdate()->with(['lines.product.category', 'lines.lotAllocations.sourceLot', 'fromSite'])->findOrFail($transfer->id);

            if ($transfer->status !== TransferStatus::InTransit) {
                throw BusinessRuleException::make('transfers.errors.not_in_transit');
            }

            foreach ($transfer->lines as $line) {
                [$lots, $quantities] = ReceiveTransferAction::lotsFor($line, $line->quantity_sent);
                $this->stock->restock($line->product, $transfer->fromSite, $line->quantity_sent, StockMovementType::TransferCancelled, $line->unit_cost_cents, $user, $reason, $transfer, $lots, $quantities);
            }

            $transfer->update(['status' => TransferStatus::Cancelled, 'cancelled_by' => $user->id, 'cancelled_at' => now(), 'notes' => trim(($transfer->notes ?? '')."\n".$reason)]);

            activity('transfers')->causedBy($user)->performedOn($transfer)->event('transfer_cancelled')
                ->withProperties(['reason' => $reason])->tap(fn ($a) => $a->site_id = $transfer->from_site_id)->log('transfer_cancelled');

            return $transfer;
        });
    }
}
