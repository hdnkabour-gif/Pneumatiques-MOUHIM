<?php

namespace App\Services\Stock;

use App\Enums\StockMovementType;
use App\Exceptions\BusinessRuleException;
use App\Exceptions\InsufficientStockException;
use App\Models\Product;
use App\Models\Site;
use App\Models\StockLevel;
use App\Models\StockMovement;
use App\Models\TyreLot;
use App\Models\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * The only code allowed to change stock (CLAUDE.md §4, D-08).
 *
 * Every call runs in a transaction and locks, in this order, the product row
 * (weighted average cost is company-wide, D-09) then the stock level row, so
 * concurrent operations on the same product are serialised without deadlock.
 * The ledger row, the stock level cache and the tyre lot caches are written
 * together; `php artisan stock:verify` re-checks them.
 */
class StockService
{
    /**
     * Goods entering a site with a purchase cost (receipt, opening stock):
     * updates the weighted average cost; new tyres get a DOT lot.
     *
     * @return Collection<int, StockMovement>
     */
    public function receive(
        Product $product,
        Site $site,
        int $quantity,
        ?int $unitCostCents,
        StockMovementType $type = StockMovementType::PurchaseReceipt,
        ?User $user = null,
        ?string $reason = null,
        ?Model $document = null,
        ?LotInput $lot = null,
    ): Collection {
        $this->assertStockable($product);
        $this->assertPositive($quantity);

        if (! $type->updatesAverageCost()) {
            throw new \InvalidArgumentException("{$type->value} is not a costed entry.");
        }

        return DB::transaction(function () use ($product, $site, $quantity, $unitCostCents, $type, $user, $reason, $document, $lot) {
            $product = $this->lockProduct($product);
            $level = $this->lockLevel($product, $site);

            $this->updateAverageCost($product, $quantity, $unitCostCents);

            $lotModel = $product->isNewTyre()
                ? $this->createLot($product, $site, new LotInput($lot?->dotWeek, $lot?->dotYear, $lot?->supplier, null, $unitCostCents))
                : null;

            return collect([$this->write($product, $site, $level, $type, $quantity, $unitCostCents, $user, $reason, $document, $lotModel)]);
        });
    }

    /**
     * Goods leaving a site (sale, transfer out, damaged, consumption…), valued
     * at the current average cost. New tyres leave from the oldest DOT first
     * (D-10). Refused when the site does not hold enough (D-45).
     *
     * @return Collection<int, StockMovement> one movement per lot consumed
     */
    public function issue(
        Product $product,
        Site $site,
        int $quantity,
        StockMovementType $type,
        ?User $user = null,
        ?string $reason = null,
        ?Model $document = null,
        bool $respectReservations = true,
    ): Collection {
        $this->assertStockable($product);
        $this->assertPositive($quantity);

        if ($type->direction() > 0) {
            throw new \InvalidArgumentException("{$type->value} is not an exit.");
        }

        return DB::transaction(function () use ($product, $site, $quantity, $type, $user, $reason, $document, $respectReservations) {
            $product = $this->lockProduct($product);
            $level = $this->lockLevel($product, $site);

            $available = $respectReservations ? $level->quantity - $level->reserved : $level->quantity;
            if ($available < $quantity) {
                throw InsufficientStockException::make('stock.errors.insufficient', [
                    'product' => $product->name,
                    'site' => $site->name,
                    'available' => max(0, $available),
                    'requested' => $quantity,
                ]);
            }

            $cost = $product->average_cost_cents;

            if (! $product->isNewTyre()) {
                return collect([$this->write($product, $site, $level, $type, -$quantity, $cost, $user, $reason, $document)]);
            }

            $movements = collect();
            $remaining = $quantity;
            foreach ($this->lotsOldestFirst($product, $site) as $lot) {
                $take = min($remaining, $lot->quantity);
                $movements->push($this->write($product, $site, $level, $type, -$take, $cost, $user, $reason, $document, $lot));
                $remaining -= $take;
                if ($remaining === 0) {
                    break;
                }
            }

            if ($remaining > 0) {
                // Level cache and lots disagree: never silently invent stock.
                throw BusinessRuleException::make('stock.errors.lots_inconsistent', ['product' => $product->name]);
            }

            return $movements;
        });
    }

    /**
     * Entry without a new purchase cost (transfer in, cancelled transfer,
     * customer return…): valued at the given cost, average cost unchanged.
     *
     * @param  list<LotInput>  $lots  for new tyres: DOT lots to recreate (quantities must add up)
     * @param  list<int>  $lotQuantities
     */
    public function restock(
        Product $product,
        Site $site,
        int $quantity,
        StockMovementType $type,
        ?int $unitCostCents,
        ?User $user = null,
        ?string $reason = null,
        ?Model $document = null,
        array $lots = [],
        array $lotQuantities = [],
    ): Collection {
        $this->assertStockable($product);
        $this->assertPositive($quantity);

        if ($type->direction() < 0 || $type->updatesAverageCost()) {
            throw new \InvalidArgumentException("{$type->value} cannot be used to restock.");
        }

        return DB::transaction(function () use ($product, $site, $quantity, $type, $unitCostCents, $user, $reason, $document, $lots, $lotQuantities) {
            $product = $this->lockProduct($product);
            $level = $this->lockLevel($product, $site);

            if (! $product->isNewTyre()) {
                return collect([$this->write($product, $site, $level, $type, $quantity, $unitCostCents, $user, $reason, $document)]);
            }

            if ($lots === []) {
                $lots = [new LotInput(unitCostCents: $unitCostCents)];
                $lotQuantities = [$quantity];
            }

            if (array_sum($lotQuantities) !== $quantity) {
                throw new \InvalidArgumentException('Lot quantities must add up to the restocked quantity.');
            }

            $movements = collect();
            foreach ($lots as $i => $lotInput) {
                if ($lotQuantities[$i] <= 0) {
                    continue;
                }
                $lot = $this->createLot($product, $site, $lotInput);
                $movements->push($this->write($product, $site, $level, $type, $lotQuantities[$i], $unitCostCents, $user, $reason, $document, $lot));
            }

            return $movements;
        });
    }

    /**
     * Inventory correction (approved count or approved adjustment): signed
     * quantity, valued at the average cost. Never below zero.
     */
    public function adjust(
        Product $product,
        Site $site,
        int $delta,
        ?User $user = null,
        ?string $reason = null,
        ?Model $document = null,
        StockMovementType $type = StockMovementType::InventoryAdjustment,
    ): Collection {
        if ($delta === 0) {
            return collect();
        }

        if ($delta < 0) {
            return $this->issue($product, $site, -$delta, $type, $user, $reason, $document, respectReservations: false);
        }

        if ($type !== StockMovementType::InventoryAdjustment) {
            throw new \InvalidArgumentException("{$type->value} cannot increase stock.");
        }

        return DB::transaction(function () use ($product, $site, $delta, $user, $reason, $document) {
            $product = $this->lockProduct($product);
            $level = $this->lockLevel($product, $site);
            $lot = $product->isNewTyre()
                ? $this->createLot($product, $site, new LotInput(unitCostCents: $product->average_cost_cents))
                : null;

            return collect([$this->write($product, $site, $level, StockMovementType::InventoryAdjustment, $delta, $product->average_cost_cents, $user, $reason, $document, $lot)]);
        });
    }

    public function quantityAt(Product $product, Site $site): int
    {
        return (int) StockLevel::query()->where(['product_id' => $product->id, 'site_id' => $site->id])->value('quantity');
    }

    /** @return Collection<int, TyreLot> */
    public function lotsOldestFirst(Product $product, Site $site, bool $lock = true): Collection
    {
        $query = TyreLot::query()
            ->where(['product_id' => $product->id, 'site_id' => $site->id])
            ->where('quantity', '>', 0)
            ->orderByRaw('dot_year IS NULL, dot_year, dot_week IS NULL, dot_week, received_at, id');

        return ($lock ? $query->lockForUpdate() : $query)->get();
    }

    private function write(
        Product $product,
        Site $site,
        StockLevel $level,
        StockMovementType $type,
        int $signedQuantity,
        ?int $unitCostCents,
        ?User $user,
        ?string $reason,
        ?Model $document,
        ?TyreLot $lot = null,
    ): StockMovement {
        $movement = new StockMovement([
            'product_id' => $product->id,
            'site_id' => $site->id,
            'tyre_lot_id' => $lot?->id,
            'type' => $type,
            'quantity' => $signedQuantity,
            'unit_cost_cents' => $unitCostCents,
            'user_id' => $user?->id ?? auth()->id(),
            'reason' => $reason,
        ]);
        $movement->document()->associate($document);
        $movement->save();

        $level->quantity += $signedQuantity;
        $level->save();

        if ($lot) {
            $lot->quantity += $signedQuantity;
            $lot->save();
        }

        return $movement;
    }

    /**
     * Holds stock for a quote (D-13): no movement, only the "reserved" part
     * of the level, so other sales cannot take it. Released on conversion,
     * refusal or expiry; the real exit happens when the sale is confirmed.
     */
    public function reserve(Product $product, Site $site, int $quantity): void
    {
        $this->assertStockable($product);
        $this->assertPositive($quantity);

        DB::transaction(function () use ($product, $site, $quantity) {
            $product = $this->lockProduct($product);
            $level = $this->lockLevel($product, $site);

            if ($level->quantity - $level->reserved < $quantity) {
                throw InsufficientStockException::make('stock.errors.insufficient', [
                    'product' => $product->name, 'site' => $site->name,
                    'available' => max(0, $level->quantity - $level->reserved), 'requested' => $quantity,
                ]);
            }

            $level->increment('reserved', $quantity);
        });
    }

    public function release(Product $product, Site $site, int $quantity): void
    {
        DB::transaction(function () use ($product, $site, $quantity) {
            $level = $this->lockLevel($this->lockProduct($product), $site);
            $level->forceFill(['reserved' => max(0, $level->reserved - $quantity)])->save();
        });
    }

    private function lockProduct(Product $product): Product
    {
        return Product::query()->with('category')->lockForUpdate()->findOrFail($product->getKey());
    }

    private function lockLevel(Product $product, Site $site): StockLevel
    {
        DB::statement(
            'INSERT INTO stock_levels (product_id, site_id, quantity, reserved, created_at, updated_at)
             VALUES (?, ?, 0, 0, now(), now()) ON CONFLICT (product_id, site_id) DO NOTHING',
            [$product->id, $site->id],
        );

        return StockLevel::query()->where(['product_id' => $product->id, 'site_id' => $site->id])->lockForUpdate()->firstOrFail();
    }

    private function createLot(Product $product, Site $site, LotInput $input): TyreLot
    {
        $lot = new TyreLot([
            'product_id' => $product->id,
            'site_id' => $site->id,
            'dot_week' => $input->dotWeek,
            'dot_year' => $input->dotYear,
            'unit_cost_cents' => $input->unitCostCents,
            'supplier' => $input->supplier,
            'received_at' => now()->toDateString(),
            'source_lot_id' => $input->sourceLotId,
        ]);
        $lot->quantity = 0;
        $lot->save();

        return $lot;
    }

    /**
     * Company-wide weighted average cost (D-09). Unknown costs are never
     * guessed: if existing stock has no known cost, or the entry has none,
     * the average becomes unknown (NULL) until stock is back to zero.
     */
    private function updateAverageCost(Product $product, int $quantity, ?int $unitCostCents): void
    {
        $onHand = (int) StockLevel::query()->where('product_id', $product->id)->sum('quantity');

        if ($onHand <= 0) {
            $average = $unitCostCents;
        } elseif ($product->average_cost_cents === null || $unitCostCents === null) {
            $average = null;
        } else {
            $total = $product->average_cost_cents * $onHand + $unitCostCents * $quantity;
            $units = $onHand + $quantity;
            $average = intdiv(2 * $total + $units, 2 * $units); // rounded half up
        }

        $product->forceFill(['average_cost_cents' => $average])->save();
    }

    private function assertStockable(Product $product): void
    {
        if (! $product->isStocked()) {
            throw BusinessRuleException::make('stock.errors.not_stocked', ['product' => $product->name]);
        }
    }

    private function assertPositive(int $quantity): void
    {
        if ($quantity <= 0) {
            throw BusinessRuleException::make('stock.errors.quantity_positive');
        }
    }
}
