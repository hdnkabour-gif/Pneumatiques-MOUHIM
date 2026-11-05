<?php

namespace App\Actions\Transfers;

use App\Enums\Permission;
use App\Enums\StockMovementType;
use App\Enums\TransferStatus;
use App\Exceptions\BusinessRuleException;
use App\Models\Product;
use App\Models\ReplenishmentRequest;
use App\Models\Site;
use App\Models\Transfer;
use App\Models\User;
use App\Services\SequenceService;
use App\Services\Stock\StockService;
use Illuminate\Support\Facades\DB;

/**
 * Creates a transfer and takes the goods out of the source site at once
 * (status "in transit", D-11). Valued at the average cost: no margin impact (D-09).
 */
class SendTransferAction
{
    public function __construct(private StockService $stock, private SequenceService $sequences) {}

    /**
     * @param  array<int, int>  $lines  product_id => quantity
     */
    public function execute(Site $from, Site $to, array $lines, User $user, ?string $notes = null, ?ReplenishmentRequest $request = null): Transfer
    {
        if (! $user->can(Permission::TransfersSend->value) || ! $user->canAccessSite($from)) {
            throw BusinessRuleException::make('app.errors.forbidden');
        }

        if ($from->is($to)) {
            throw BusinessRuleException::make('transfers.errors.same_site');
        }

        $lines = array_filter($lines, fn ($qty) => (int) $qty > 0);
        if ($lines === []) {
            throw BusinessRuleException::make('transfers.errors.no_lines');
        }

        return DB::transaction(function () use ($from, $to, $lines, $user, $notes, $request) {
            $transfer = Transfer::query()->create([
                'number' => $this->sequences->nextDocumentNumber('TR'),
                'from_site_id' => $from->id,
                'to_site_id' => $to->id,
                'status' => TransferStatus::InTransit,
                'replenishment_request_id' => $request?->id,
                'sent_by' => $user->id,
                'sent_at' => now(),
                'notes' => $notes,
            ]);

            foreach ($lines as $productId => $quantity) {
                $product = Product::query()->findOrFail($productId);
                $movements = $this->stock->issue($product, $from, (int) $quantity, StockMovementType::TransferOut, $user, $transfer->number, $transfer);

                $line = $transfer->lines()->create([
                    'product_id' => $product->id,
                    'quantity_sent' => (int) $quantity,
                    'unit_cost_cents' => $movements->first()->unit_cost_cents,
                ]);

                foreach ($movements->whereNotNull('tyre_lot_id') as $movement) {
                    $line->lotAllocations()->create(['source_lot_id' => $movement->tyre_lot_id, 'quantity' => -$movement->quantity]);
                }
            }

            activity('transfers')->causedBy($user)->performedOn($transfer)->event('transfer_sent')
                ->withProperties(['lines' => $lines, 'from' => $from->code, 'to' => $to->code])
                ->tap(fn ($a) => $a->site_id = $from->id)->log('transfer_sent');

            return $transfer->load('lines');
        });
    }
}
