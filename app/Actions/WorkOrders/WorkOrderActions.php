<?php

namespace App\Actions\WorkOrders;

use App\Enums\Permission;
use App\Enums\WorkOrderStatus;
use App\Exceptions\BusinessRuleException;
use App\Models\User;
use App\Models\WorkOrder;
use Illuminate\Support\Facades\DB;

/**
 * Workshop follow-up entered by the seller (D-53): technicians, statuses,
 * observations, next oil change. Times are recorded automatically.
 */
class WorkOrderActions
{
    /** Allowed moves; cancelling is kept for users who decide approvals. */
    public const TRANSITIONS = [
        'pending' => ['in_progress'],
        'in_progress' => ['paused', 'done'],
        'paused' => ['in_progress', 'done'],
        'done' => ['delivered', 'in_progress'],
        'delivered' => [],
        'cancelled' => [],
    ];

    public function move(WorkOrder $order, WorkOrderStatus $to, User $user): WorkOrder
    {
        $this->authorize($order, $user);

        return DB::transaction(function () use ($order, $to, $user) {
            $order = WorkOrder::query()->lockForUpdate()->findOrFail($order->id);
            $allowed = self::TRANSITIONS[$order->status->value];

            $canCancel = $to === WorkOrderStatus::Cancelled && $user->can(Permission::ApprovalsDecide->value)
                && ! in_array($order->status, [WorkOrderStatus::Delivered, WorkOrderStatus::Cancelled], true);

            if (! $canCancel && ! in_array($to->value, $allowed, true)) {
                throw BusinessRuleException::make('work_orders.errors.transition', ['from' => $order->status->label(), 'to' => $to->label()]);
            }

            $order->status = $to;
            match ($to) {
                WorkOrderStatus::InProgress => $order->started_at ??= now(),
                WorkOrderStatus::Done => $order->finished_at = now(),
                WorkOrderStatus::Delivered => $order->delivered_at = now(),
                default => null,
            };
            $order->save();

            activity('work_orders')->causedBy($user)->performedOn($order)->event('work_order_'.$to->value)
                ->tap(fn ($a) => $a->site_id = $order->site_id)->log('work_order_'.$to->value);

            return $order;
        });
    }

    /** @param  list<int>  $technicianIds */
    public function assign(WorkOrder $order, array $technicianIds, User $user): void
    {
        $this->authorize($order, $user);

        $ids = User::query()->whereKey($technicianIds)->where('is_active', true)->get()
            ->filter(fn (User $t) => $t->canAccessSite($order->site_id))->modelKeys();
        $order->technicians()->sync($ids);
    }

    public function note(WorkOrder $order, ?string $notes, User $user, ?int $nextOilChangeKm = null): void
    {
        $this->authorize($order, $user);

        DB::transaction(function () use ($order, $notes, $nextOilChangeKm) {
            $order->update(['notes' => filled($notes) ? trim($notes) : null]);
            if ($nextOilChangeKm && $order->vehicle) {
                $order->vehicle->update(['next_oil_change_km' => $nextOilChangeKm]);
            }
        });
    }

    private function authorize(WorkOrder $order, User $user): void
    {
        if (! $user->can(Permission::WorkOrdersManage->value) || ! $user->canAccessSite($order->site_id)) {
            throw BusinessRuleException::make('app.errors.forbidden');
        }
    }
}
