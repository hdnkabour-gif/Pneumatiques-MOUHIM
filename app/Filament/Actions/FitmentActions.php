<?php

namespace App\Filament\Actions;

use App\Actions\Vehicles\FitmentStatusAction;
use App\Enums\CompatibilityStatus;
use App\Enums\Permission;
use App\Exceptions\BusinessRuleException;
use App\Models\OilFitment;
use App\Models\TyreFitment;
use Filament\Actions\Action;
use Filament\Forms\Components\Textarea;
use Filament\Notifications\Notification;
use Filament\Support\Icons\Heroicon;

final class FitmentActions
{
    /** @return list<Action> */
    public static function all(): array
    {
        return [
            self::make('verify', CompatibilityStatus::Verified, Permission::CompatibilitiesVerify, 'success', Heroicon::OutlinedCheckBadge)
                ->modalDescription(__('vehicles.messages.verify_confirm')),
            self::make('to_check', CompatibilityStatus::ToCheck, Permission::CompatibilitiesManage, 'warning', Heroicon::OutlinedArrowUturnLeft),
            self::make('obsolete', CompatibilityStatus::Obsolete, Permission::CompatibilitiesManage, 'danger', Heroicon::OutlinedArchiveBoxXMark),
        ];
    }

    private static function make(string $name, CompatibilityStatus $status, Permission $permission, string $color, Heroicon $icon): Action
    {
        return Action::make($name)->label(__("vehicles.actions.{$name}"))->color($color)->icon($icon)
            ->requiresConfirmation()
            ->visible(fn (TyreFitment|OilFitment $record) => $record->status !== $status && auth()->user()->can($permission->value))
            ->schema([Textarea::make('comment')->label(__('vehicles.fields.comment'))])
            ->action(function (TyreFitment|OilFitment $record, array $data) use ($status) {
                try {
                    app(FitmentStatusAction::class)->execute($record, $status, auth()->user(), $data['comment'] ?? null);
                    Notification::make()->success()->title(__('vehicles.messages.status_changed'))->send();
                } catch (BusinessRuleException $e) {
                    Notification::make()->danger()->title($e->getMessage())->send();
                }
            });
    }
}
