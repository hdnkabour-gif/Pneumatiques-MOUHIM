<?php

namespace App\Actions\UsedTyres;

use App\Enums\CategoryType;
use App\Enums\Permission;
use App\Enums\StockMovementType;
use App\Enums\UsedTyreCheck;
use App\Enums\UsedTyreOrigin;
use App\Enums\UsedTyreStatus;
use App\Exceptions\BusinessRuleException;
use App\Models\Category;
use App\Models\Site;
use App\Models\StockMovement;
use App\Models\UsedTyre;
use App\Models\User;
use App\Services\SequenceService;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\DB;

/**
 * Used tyre life cycle (§5.4, D-12): registered "awaiting inspection" →
 * mandatory safety inspection → "in stock" or "unsellable" → scrapped / sold.
 * Each entry and exit is also written to the stock ledger (D-46).
 */
class UsedTyreActions
{
    public function __construct(private SequenceService $sequences) {}

    /** @param  array<string, mixed>  $data  fillable attributes of UsedTyre */
    public function register(array $data, Site $site, UsedTyreOrigin $origin, User $user, ?Model $originDocument = null): UsedTyre
    {
        $this->authorize($user, Permission::UsedTyresManage, $site);

        return DB::transaction(function () use ($data, $site, $origin, $user, $originDocument) {
            $prefix = Category::query()->where('type', CategoryType::UsedTyre->value)->value('sku_prefix') ?? 'PO';

            $tyre = new UsedTyre($data);
            $tyre->forceFill([
                'code' => $this->sequences->nextSku($prefix),
                'site_id' => $site->id,
                'status' => UsedTyreStatus::PendingInspection,
                'origin' => $origin,
                'created_by' => $user->id,
            ]);
            $tyre->originDocument()->associate($originDocument);
            $tyre->save();

            $this->movement($tyre, $origin === UsedTyreOrigin::TradeIn ? StockMovementType::TradeIn : StockMovementType::PurchaseReceipt, 1, $user, $originDocument);

            return $tyre;
        });
    }

    /**
     * Safety inspection: every check must be answered; a single dangerous
     * point makes the tyre unsellable (§5.4).
     *
     * @param  array<string, bool>  $checks  UsedTyreCheck value => true when OK
     */
    public function inspect(UsedTyre $tyre, array $checks, User $user, ?int $treadDepthTenths = null, ?string $notes = null): UsedTyre
    {
        $this->authorize($user, Permission::UsedTyresInspect, $tyre->site);

        foreach (UsedTyreCheck::cases() as $check) {
            if (! array_key_exists($check->value, $checks)) {
                throw BusinessRuleException::make('used_tyres.errors.check_missing', ['check' => $check->label()]);
            }
        }

        return DB::transaction(function () use ($tyre, $checks, $user, $treadDepthTenths, $notes) {
            $tyre = UsedTyre::query()->lockForUpdate()->findOrFail($tyre->id);

            if (! in_array($tyre->status, [UsedTyreStatus::PendingInspection, UsedTyreStatus::InStock], true)) {
                throw BusinessRuleException::make('used_tyres.errors.cannot_inspect');
            }

            $normalized = [];
            foreach (UsedTyreCheck::cases() as $check) {
                $normalized[$check->value] = (bool) $checks[$check->value];
            }
            $passed = ! in_array(false, $normalized, true);

            $tyre->inspections()->create([
                'inspector_id' => $user->id,
                'checks' => $normalized,
                'tread_depth_tenths' => $treadDepthTenths,
                'passed' => $passed,
                'notes' => $notes,
                'created_at' => now(),
            ]);

            $tyre->forceFill([
                'status' => $passed ? UsedTyreStatus::InStock : UsedTyreStatus::Unsellable,
                'tread_depth_tenths' => $treadDepthTenths ?? $tyre->tread_depth_tenths,
            ])->save();

            return $tyre;
        });
    }

    public function scrap(UsedTyre $tyre, User $user, string $reason): UsedTyre
    {
        $this->authorize($user, Permission::UsedTyresManage, $tyre->site);

        if (trim($reason) === '') {
            throw BusinessRuleException::make('approvals.errors.reason_required');
        }

        return DB::transaction(function () use ($tyre, $user, $reason) {
            $tyre = UsedTyre::query()->lockForUpdate()->findOrFail($tyre->id);

            if (in_array($tyre->status, [UsedTyreStatus::Sold, UsedTyreStatus::Scrapped], true)) {
                throw BusinessRuleException::make('used_tyres.errors.not_in_stock');
            }

            $tyre->forceFill(['status' => UsedTyreStatus::Scrapped])->save();
            $this->movement($tyre, StockMovementType::Damaged, -1, $user, null, $reason);

            return $tyre;
        });
    }

    private function movement(UsedTyre $tyre, StockMovementType $type, int $quantity, User $user, ?Model $document = null, ?string $reason = null): void
    {
        $movement = new StockMovement([
            'used_tyre_id' => $tyre->id,
            'site_id' => $tyre->site_id,
            'type' => $type,
            'quantity' => $quantity,
            'unit_cost_cents' => $tyre->cost_cents,
            'user_id' => $user->id,
            'reason' => $reason ?? $tyre->code,
        ]);
        $movement->document()->associate($document);
        $movement->save();
    }

    private function authorize(User $user, Permission $permission, Site $site): void
    {
        if (! $user->can($permission->value) || ! $user->canAccessSite($site)) {
            throw BusinessRuleException::make('app.errors.forbidden');
        }
    }
}
