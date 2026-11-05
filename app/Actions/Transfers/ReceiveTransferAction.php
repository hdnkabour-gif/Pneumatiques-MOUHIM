<?php

namespace App\Actions\Transfers;

use App\Enums\Permission;
use App\Enums\StockMovementType;
use App\Enums\TransferStatus;
use App\Exceptions\BusinessRuleException;
use App\Models\Transfer;
use App\Models\TransferLine;
use App\Models\User;
use App\Services\Stock\LotInput;
use App\Services\Stock\StockService;
use Illuminate\Support\Facades\DB;

/**
 * Enters the received quantities at the destination (D-11). A shortfall
 * requires a reason and stays recorded on the line; DOT lots follow the goods.
 */
class ReceiveTransferAction
{
    public function __construct(private StockService $stock) {}

    /**
     * @param  array<int, int>  $received  line_id => quantity received (defaults to quantity sent)
     * @param  array<int, string>  $reasons  line_id => discrepancy reason
     */
    public function execute(Transfer $transfer, User $user, array $received = [], array $reasons = []): Transfer
    {
        if (! $user->can(Permission::TransfersReceive->value) || ! $user->canAccessSite($transfer->to_site_id)) {
            throw BusinessRuleException::make('app.errors.forbidden');
        }

        return DB::transaction(function () use ($transfer, $user, $received, $reasons) {
            $transfer = Transfer::query()->lockForUpdate()->with(['lines.product.category', 'lines.lotAllocations.sourceLot', 'toSite'])->findOrFail($transfer->id);

            if ($transfer->status !== TransferStatus::InTransit) {
                throw BusinessRuleException::make('transfers.errors.not_in_transit');
            }

            foreach ($transfer->lines as $line) {
                $qty = (int) ($received[$line->id] ?? $line->quantity_sent);
                $reason = trim((string) ($reasons[$line->id] ?? ''));

                if ($qty < 0 || $qty > $line->quantity_sent) {
                    throw BusinessRuleException::make('transfers.errors.invalid_received', ['product' => $line->product->name]);
                }
                if ($qty < $line->quantity_sent && $reason === '') {
                    throw BusinessRuleException::make('transfers.errors.reason_required', ['product' => $line->product->name]);
                }

                if ($qty > 0) {
                    [$lots, $quantities] = $this->lotsFor($line, $qty);
                    $this->stock->restock($line->product, $transfer->toSite, $qty, StockMovementType::TransferIn, $line->unit_cost_cents, $user, $transfer->number, $transfer, $lots, $quantities);
                }

                $line->update(['quantity_received' => $qty, 'discrepancy_reason' => $reason !== '' ? $reason : null]);
            }

            $transfer->update(['status' => TransferStatus::Received, 'received_by' => $user->id, 'received_at' => now()]);

            activity('transfers')->causedBy($user)->performedOn($transfer)->event('transfer_received')
                ->withProperties(['received' => $transfer->lines->mapWithKeys(fn ($l) => [$l->product_id => [$l->quantity_sent, $l->quantity_received, $l->discrepancy_reason]])])
                ->tap(fn ($a) => $a->site_id = $transfer->to_site_id)->log('transfer_received');

            return $transfer;
        });
    }

    /**
     * Distributes the received quantity over the source lots, oldest DOT first.
     *
     * @return array{0: list<LotInput>, 1: list<int>}
     */
    public static function lotsFor(TransferLine $line, int $quantity): array
    {
        $lots = [];
        $quantities = [];
        $remaining = $quantity;

        foreach ($line->lotAllocations as $allocation) {
            if ($remaining === 0) {
                break;
            }
            $take = min($remaining, $allocation->quantity);
            $source = $allocation->sourceLot;
            $lots[] = new LotInput($source->dot_week, $source->dot_year, $source->supplier, $source->id, $source->unit_cost_cents);
            $quantities[] = $take;
            $remaining -= $take;
        }

        return [$lots, $quantities];
    }
}
