<?php

namespace App\Filament\Resources\ApprovalRequests\Pages;

use App\Enums\Permission;
use App\Exceptions\BusinessRuleException;
use App\Filament\Resources\ApprovalRequests\ApprovalRequestResource;
use App\Models\ApprovalRequest;
use App\Services\Approvals\ApprovalService;
use Filament\Actions\Action;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\ViewRecord;
use Filament\Support\Icons\Heroicon;

class ViewApprovalRequest extends ViewRecord
{
    protected static string $resource = ApprovalRequestResource::class;

    protected function getHeaderActions(): array
    {
        /** @var ApprovalRequest $approval */
        $approval = $this->record;
        $user = auth()->user();
        $canDecide = $approval->isPending() && $user->can(Permission::ApprovalsDecide->value);
        $decide = function (bool $approve, ?string $comment) use ($approval) {
            try {
                app(ApprovalService::class)->decide($approval, auth()->user(), $approve, $comment);
                Notification::make()->success()->title(__($approve ? 'approvals.messages.approved' : 'approvals.messages.rejected'))->send();
                $this->redirect(ApprovalRequestResource::getUrl('view', ['record' => $approval]));
            } catch (BusinessRuleException $e) {
                Notification::make()->danger()->title($e->getMessage())->send();
            }
        };

        return [
            Action::make('approve')->label(__('approvals.actions.approve'))->icon(Heroicon::OutlinedCheck)->color('success')->size('lg')
                ->visible($canDecide)
                ->schema([Textarea::make('comment')->label(__('approvals.fields.comment'))])
                ->action(fn (array $data) => $decide(true, $data['comment'] ?? null)),
            Action::make('reject')->label(__('approvals.actions.reject'))->icon(Heroicon::OutlinedXMark)->color('danger')->size('lg')
                ->visible($canDecide)
                ->schema([Textarea::make('comment')->label(__('approvals.fields.comment'))])
                ->action(fn (array $data) => $decide(false, $data['comment'] ?? null)),
            // On-site alternative (§4): the owner types his PIN on the employee's screen.
            Action::make('approveWithPin')->label(__('approvals.actions.approve_with_pin'))->icon(Heroicon::OutlinedKey)->color('gray')
                ->visible($approval->isPending() && ! $user->can(Permission::ApprovalsDecide->value) && $approval->requested_by === $user->id)
                ->schema([TextInput::make('pin')->label(__('approvals.fields.pin'))->password()->required()->autocomplete('off')])
                ->action(function (array $data) use ($approval) {
                    try {
                        app(ApprovalService::class)->decideWithPin($approval, (string) $data['pin']);
                        Notification::make()->success()->title(__('approvals.messages.approved'))->send();
                        $this->redirect(ApprovalRequestResource::getUrl('view', ['record' => $approval]));
                    } catch (BusinessRuleException $e) {
                        Notification::make()->danger()->title($e->getMessage())->send();
                    }
                }),
        ];
    }
}
