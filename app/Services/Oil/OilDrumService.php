<?php

namespace App\Services\Oil;

use App\Enums\OilDrumMovementType as Type;
use App\Enums\OilDrumStatus;
use App\Enums\Permission;
use App\Exceptions\BusinessRuleException;
use App\Models\OilDrum;
use App\Models\OilDrumMovement;
use App\Models\Product;
use App\Models\Site;
use App\Models\User;
use App\Services\SequenceService;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\DB;

/**
 * Bulk oil drums (D-06 revised). Every change is a ledger movement written
 * in the same transaction as the drum's cached remaining quantity, with the
 * drum row locked: two sales can never pour the same litres.
 */
class OilDrumService
{
    public function __construct(private SequenceService $sequences) {}

    /** Cost per litre = (purchase + extra costs) ÷ litres really received, rounded to the cent. */
    public static function costPerLitre(int $purchaseCents, int $extraCents, int $receivedMl): int
    {
        return intdiv(($purchaseCents + $extraCents) * 1000 * 2 + $receivedMl, $receivedMl * 2);
    }

    /** Cost of a quantity poured, from the drum's cost per litre. */
    public static function costOf(int $costPerLitreCents, int $ml): int
    {
        return intdiv($costPerLitreCents * $ml * 2 + 1000, 2000);
    }

    /**
     * @param  array{capacity_ml: int, received_ml?: ?int, purchase_cost_cents: int, extra_cost_cents?: ?int, supplier?: ?string, invoice_ref?: ?string, received_at?: ?string, notes?: ?string}  $data
     */
    public function receive(Product $product, Site $site, array $data, User $user): OilDrum
    {
        if (! $user->can(Permission::StockReceive->value) || ! $user->canAccessSite($site)) {
            throw BusinessRuleException::make('app.errors.forbidden');
        }
        if (! $product->isBulk()) {
            throw BusinessRuleException::make('oil_drums.errors.not_bulk', ['product' => $product->name]);
        }

        $received = (int) ($data['received_ml'] ?? 0) ?: (int) $data['capacity_ml'];
        if ($received <= 0 || (int) $data['purchase_cost_cents'] < 0) {
            throw BusinessRuleException::make('oil_drums.errors.quantity');
        }

        return DB::transaction(function () use ($product, $site, $data, $user, $received) {
            $drum = new OilDrum([
                'code' => $this->sequences->nextSku('FUT'),
                'product_id' => $product->id,
                'site_id' => $site->id,
                'capacity_ml' => (int) $data['capacity_ml'],
                'received_ml' => $received,
                'purchase_cost_cents' => (int) $data['purchase_cost_cents'],
                'extra_cost_cents' => (int) ($data['extra_cost_cents'] ?? 0),
                'supplier' => $data['supplier'] ?? null,
                'invoice_ref' => $data['invoice_ref'] ?? null,
                'received_at' => $data['received_at'] ?? today(),
                'notes' => $data['notes'] ?? null,
                'created_by' => $user->id,
            ]);
            $drum->forceFill([
                'status' => OilDrumStatus::Closed,
                'remaining_ml' => 0,
                'cost_per_litre_cents' => self::costPerLitre((int) $data['purchase_cost_cents'], (int) ($data['extra_cost_cents'] ?? 0), $received),
            ])->save();

            $this->write($drum, Type::Receipt, $received, $user, null);

            return $drum->refresh();
        });
    }

    /** Sale by the litre or workshop use: only from a usable drum of the site, never below zero. */
    public function draw(OilDrum $drum, int $ml, Type $type, ?User $user, ?Model $document = null, ?Site $site = null): OilDrumMovement
    {
        if ($ml <= 0 || ! in_array($type, [Type::Sale, Type::Service], true)) {
            throw BusinessRuleException::make('oil_drums.errors.quantity');
        }

        return DB::transaction(function () use ($drum, $ml, $type, $user, $document, $site) {
            $drum = OilDrum::query()->lockForUpdate()->findOrFail($drum->id);

            if ($site && $drum->site_id !== $site->id) {
                throw BusinessRuleException::make('oil_drums.errors.other_site', ['drum' => $drum->code]);
            }
            if (in_array($drum->status, [OilDrumStatus::Blocked, OilDrumStatus::Empty], true)) {
                throw BusinessRuleException::make('oil_drums.errors.not_usable', ['drum' => $drum->code]);
            }
            if ($drum->remaining_ml < $ml) {
                throw BusinessRuleException::make('oil_drums.errors.insufficient', [
                    'drum' => $drum->code, 'available' => OilDrum::litres($drum->remaining_ml), 'requested' => OilDrum::litres($ml),
                ]);
            }

            if ($drum->status === OilDrumStatus::Closed) {
                $drum->forceFill(['status' => OilDrumStatus::Open, 'opened_at' => now()]);
            }

            return $this->write($drum, $type, -$ml, $user, $document);
        });
    }

    /** Customer return of oil poured from this drum (credit note). */
    public function giveBack(OilDrum $drum, int $ml, ?User $user, ?Model $document = null, ?string $reason = null): OilDrumMovement
    {
        return DB::transaction(function () use ($drum, $ml, $user, $document, $reason) {
            $drum = OilDrum::query()->lockForUpdate()->findOrFail($drum->id);
            if ($drum->status === OilDrumStatus::Empty) {
                $drum->forceFill(['status' => OilDrumStatus::Open, 'emptied_at' => null]);
            }

            return $this->write($drum, Type::Return, $ml, $user, $document, $reason);
        });
    }

    /** Count correction (dipstick): signed, with a reason, by whoever approves inventories. */
    public function adjust(OilDrum $drum, int $deltaMl, string $reason, User $user): OilDrumMovement
    {
        if (! $user->canAny([Permission::ApprovalsDecide->value, Permission::InventoryApprove->value])) {
            throw BusinessRuleException::make('app.errors.forbidden');
        }
        if (trim($reason) === '' || $deltaMl === 0) {
            throw BusinessRuleException::make('approvals.errors.reason_required');
        }

        return DB::transaction(function () use ($drum, $deltaMl, $reason, $user) {
            $drum = OilDrum::query()->lockForUpdate()->findOrFail($drum->id);
            if ($drum->remaining_ml + $deltaMl < 0) {
                throw BusinessRuleException::make('oil_drums.errors.insufficient', [
                    'drum' => $drum->code, 'available' => OilDrum::litres($drum->remaining_ml), 'requested' => OilDrum::litres(-$deltaMl),
                ]);
            }

            return $this->write($drum, Type::Adjustment, $deltaMl, $user, null, trim($reason));
        });
    }

    /** The whole drum moves to another site (two ledger lines). */
    public function transfer(OilDrum $drum, Site $to, User $user): OilDrum
    {
        if (! $user->can(Permission::TransfersSend->value) || ! $user->canAccessSite($drum->site_id)) {
            throw BusinessRuleException::make('app.errors.forbidden');
        }

        return DB::transaction(function () use ($drum, $to, $user) {
            $drum = OilDrum::query()->lockForUpdate()->findOrFail($drum->id);
            if ($drum->site_id === $to->id || $drum->remaining_ml === 0) {
                throw BusinessRuleException::make('oil_drums.errors.transfer');
            }
            $quantity = $drum->remaining_ml;
            $this->write($drum, Type::TransferOut, -$quantity, $user, null, $to->code);
            $drum->forceFill(['site_id' => $to->id])->save();
            $this->write($drum, Type::TransferIn, $quantity, $user, null);

            return $drum->refresh();
        });
    }

    public function setBlocked(OilDrum $drum, bool $blocked, User $user): void
    {
        if (! $user->canAny([Permission::StockAdjust->value, Permission::ApprovalsDecide->value])) {
            throw BusinessRuleException::make('app.errors.forbidden');
        }
        $drum->forceFill(['status' => $blocked ? OilDrumStatus::Blocked
            : ($drum->remaining_ml === 0 ? OilDrumStatus::Empty : ($drum->opened_at ? OilDrumStatus::Open : OilDrumStatus::Closed))])->save();
        activity('stock')->causedBy($user)->performedOn($drum)->event($blocked ? 'drum_blocked' : 'drum_unblocked')
            ->tap(fn ($a) => $a->site_id = $drum->site_id)->log($blocked ? 'drum_blocked' : 'drum_unblocked');
    }

    private function write(OilDrum $drum, Type $type, int $ml, ?User $user, ?Model $document, ?string $reason = null): OilDrumMovement
    {
        $movement = new OilDrumMovement([
            'oil_drum_id' => $drum->id,
            'site_id' => $drum->site_id,
            'type' => $type,
            'quantity_ml' => $ml,
            'cost_per_litre_cents' => $drum->cost_per_litre_cents,
            'user_id' => $user?->id,
            'reason' => $reason,
            'created_at' => now(),
        ]);
        $movement->document()->associate($document);
        $movement->save();

        $remaining = $drum->remaining_ml + $ml;
        $drum->forceFill([
            'remaining_ml' => $remaining,
            'status' => $remaining === 0 && $drum->status !== OilDrumStatus::Blocked ? OilDrumStatus::Empty : $drum->status,
            'emptied_at' => $remaining === 0 ? now() : $drum->emptied_at,
        ])->save();

        return $movement;
    }
}
