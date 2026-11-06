<?php

namespace App\Services\Approvals;

use App\Enums\ApprovalStatus;
use App\Enums\ApprovalType;
use App\Enums\Permission;
use App\Exceptions\BusinessRuleException;
use App\Models\ApprovalRequest;
use App\Models\Site;
use App\Models\User;
use App\Services\Stock\StockService;
use Filament\Actions\Action;
use Filament\Notifications\Notification;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\RateLimiter;

/**
 * Central approval module (§4, D-25): a user asks with a reason, the owner
 * (or any user with approvals.decide) approves or rejects from the app, or
 * types his personal PIN on the requester's screen. Everything is journalled.
 */
class ApprovalService
{
    public function handler(ApprovalType $type): ApprovalHandler
    {
        return match ($type) {
            ApprovalType::StockAdjustment => new StockAdjustmentHandler(app(StockService::class)),
            ApprovalType::DamagedStock => new StockAdjustmentHandler(app(StockService::class), damaged: true),
            ApprovalType::Discount, ApprovalType::PriceOverride, ApprovalType::BelowCost, ApprovalType::CreditLimit => new SaleApprovalHandler($type),
            default => throw new \LogicException("No handler for {$type->value} yet."),
        };
    }

    /**
     * Records the request. If the requester may decide himself (owner), the
     * operation is executed immediately and recorded as self-approved.
     */
    public function request(ApprovalType $type, string $reason, array $payload, User $requester, ?Site $site = null, ?Model $subject = null): ApprovalRequest
    {
        if (trim($reason) === '') {
            throw BusinessRuleException::make('approvals.errors.reason_required');
        }

        $approval = DB::transaction(function () use ($type, $reason, $payload, $requester, $site, $subject) {
            $approval = new ApprovalRequest([
                'type' => $type,
                'status' => ApprovalStatus::Pending,
                'site_id' => $site?->id,
                'requested_by' => $requester->id,
                'reason' => trim($reason),
                'payload' => $payload,
            ]);
            $approval->subject()->associate($subject);
            $approval->save();

            activity('approvals')->causedBy($requester)->performedOn($approval)->event('approval_requested')
                ->withProperties(['type' => $type->value, 'payload' => $payload, 'reason' => $reason])
                ->tap(fn ($a) => $a->site_id = $site?->id)->log('approval_requested');

            return $approval;
        });

        if ($requester->can(Permission::ApprovalsDecide->value)) {
            return $this->decide($approval, $requester, true, null, 'self');
        }

        $this->notifyApprovers($approval);

        return $approval;
    }

    public function decide(ApprovalRequest $approval, User $decider, bool $approve, ?string $comment = null, string $via = 'app'): ApprovalRequest
    {
        if (! $decider->can(Permission::ApprovalsDecide->value)) {
            throw BusinessRuleException::make('app.errors.forbidden');
        }

        $approval = DB::transaction(function () use ($approval, $decider, $approve, $comment, $via) {
            $approval = ApprovalRequest::query()->lockForUpdate()->findOrFail($approval->id);

            if (! $approval->isPending()) {
                throw BusinessRuleException::make('approvals.errors.already_decided');
            }

            if ($approve) {
                $this->handler($approval->type)->execute($approval, $decider);
            }

            $approval->update([
                'status' => $approve ? ApprovalStatus::Approved : ApprovalStatus::Rejected,
                'decided_by' => $decider->id,
                'decided_at' => now(),
                'decided_via' => $via,
                'decision_comment' => $comment,
            ]);

            activity('approvals')->causedBy($decider)->performedOn($approval)->event($approve ? 'approval_granted' : 'approval_rejected')
                ->withProperties(['via' => $via, 'comment' => $comment])
                ->tap(fn ($a) => $a->site_id = $approval->site_id)->log($approve ? 'approval_granted' : 'approval_rejected');

            return $approval;
        });

        if ($approval->requester && ! $approval->requester->is($decider)) {
            Notification::make()
                ->title(__($approve ? 'approvals.notifications.granted' : 'approvals.notifications.rejected'))
                ->body($this->handler($approval->type)->summary($approval))
                ->{$approve ? 'success' : 'danger'}()
                ->sendToDatabase($approval->requester);
        }

        return $approval;
    }

    /**
     * On-site approval: the owner types his PIN on the requester's device.
     * Five wrong PINs per minute lock the attempt.
     */
    public function decideWithPin(ApprovalRequest $approval, string $pin, bool $approve = true): ApprovalRequest
    {
        $key = 'approval-pin:'.$approval->id;

        if (RateLimiter::tooManyAttempts($key, 5)) {
            throw BusinessRuleException::make('approvals.errors.pin_locked');
        }

        $approver = User::query()->whereNotNull('approval_pin')->where('is_active', true)->get()
            ->first(fn (User $u) => $u->can(Permission::ApprovalsDecide->value) && Hash::check($pin, $u->approval_pin));

        if (! $approver) {
            RateLimiter::hit($key, 60);
            throw BusinessRuleException::make('approvals.errors.pin_invalid');
        }

        RateLimiter::clear($key);

        return $this->decide($approval, $approver, $approve, null, 'pin');
    }

    private function notifyApprovers(ApprovalRequest $approval): void
    {
        $approvers = User::query()->where('is_active', true)->get()
            ->filter(fn (User $u) => $u->can(Permission::ApprovalsDecide->value));

        foreach ($approvers as $approver) {
            Notification::make()
                ->title(__('approvals.notifications.new', ['type' => $approval->type->label()]))
                ->body($this->handler($approval->type)->summary($approval).' – '.$approval->reason)
                ->warning()
                ->actions([Action::make('open')->label(__('approvals.actions.open'))->url('/admin/approval-requests/'.$approval->id)])
                ->sendToDatabase($approver);
        }
    }
}
