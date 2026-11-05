<?php

namespace App\Actions\Transfers;

use App\Enums\ReplenishmentStatus;
use App\Exceptions\BusinessRuleException;
use App\Models\ReplenishmentRequest;
use App\Models\Transfer;
use App\Models\User;
use Illuminate\Support\Facades\DB;

/** The depot sends the requested goods: the request becomes a transfer. */
class ConvertReplenishmentRequestAction
{
    public function __construct(private SendTransferAction $send) {}

    /** @param  array<int, int>|null  $quantities  product_id => quantity to send (defaults to requested) */
    public function execute(ReplenishmentRequest $request, User $user, ?array $quantities = null): Transfer
    {
        return DB::transaction(function () use ($request, $user, $quantities) {
            $request = ReplenishmentRequest::query()->lockForUpdate()->with(['lines', 'site', 'sourceSite'])->findOrFail($request->id);

            if ($request->status !== ReplenishmentStatus::Pending) {
                throw BusinessRuleException::make('transfers.errors.request_not_pending');
            }

            $lines = $quantities ?? $request->lines->mapWithKeys(fn ($l) => [$l->product_id => $l->quantity])->all();
            $transfer = $this->send->execute($request->sourceSite, $request->site, $lines, $user, $request->number, $request);

            $request->update(['status' => ReplenishmentStatus::Converted]);

            return $transfer;
        });
    }
}
