<?php

namespace App\Filament\Resources\UsedTyres\Pages;

use App\Actions\UsedTyres\UsedTyreActions;
use App\Enums\Permission;
use App\Enums\UsedTyreCheck;
use App\Enums\UsedTyreStatus;
use App\Exceptions\BusinessRuleException;
use App\Filament\Resources\UsedTyres\UsedTyreResource;
use App\Models\UsedTyre;
use Filament\Actions\Action;
use Filament\Actions\EditAction;
use Filament\Forms\Components\Radio;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\ViewRecord;
use Filament\Schemas\Components\Section;
use Filament\Support\Icons\Heroicon;

class ViewUsedTyre extends ViewRecord
{
    protected static string $resource = UsedTyreResource::class;

    protected function getHeaderActions(): array
    {
        /** @var UsedTyre $tyre */
        $tyre = $this->record;
        $user = auth()->user();

        return [
            // Quick yes/no form, finger friendly (§5.4).
            Action::make('inspect')->label(__('used_tyres.actions.inspect'))->icon(Heroicon::OutlinedShieldCheck)->color('warning')->size('lg')
                ->visible(in_array($tyre->status, [UsedTyreStatus::PendingInspection, UsedTyreStatus::InStock], true)
                    && $user->can(Permission::UsedTyresInspect->value) && $user->canAccessSite($tyre->site_id))
                ->modalHeading(__('used_tyres.sections.inspection'))
                ->modalDescription(__('used_tyres.sections.inspection_help'))
                ->schema([
                    Section::make()->columns(['default' => 1, 'md' => 2])->schema(array_map(
                        fn (UsedTyreCheck $check) => Radio::make("checks.{$check->value}")->label($check->label())
                            ->options(['1' => __('app.yes'), '0' => __('app.no')])->inline()->required(),
                        UsedTyreCheck::cases(),
                    )),
                    TextInput::make('tread_depth')->label(__('used_tyres.fields.tread_depth'))->numeric()->minValue(0)->maxValue(20)->step(0.1)
                        ->default($tyre->treadDepthMm()),
                    Textarea::make('notes')->label(__('used_tyres.fields.notes')),
                ])
                ->action(function (array $data) use ($tyre) {
                    $checks = array_map(fn ($v) => $v === '1' || $v === 1 || $v === true, $data['checks'] ?? []);
                    $tread = blank($data['tread_depth'] ?? null) ? null : (int) round(((float) str_replace(',', '.', (string) $data['tread_depth'])) * 10);

                    try {
                        $result = app(UsedTyreActions::class)->inspect($tyre, $checks, auth()->user(), $tread, $data['notes'] ?? null);
                        $passed = $result->status === UsedTyreStatus::InStock;
                        Notification::make()->{$passed ? 'success' : 'danger'}()->title(__($passed ? 'used_tyres.messages.passed' : 'used_tyres.messages.failed'))->send();
                        $this->redirect(UsedTyreResource::getUrl('view', ['record' => $tyre]));
                    } catch (BusinessRuleException $e) {
                        Notification::make()->danger()->title($e->getMessage())->send();
                    }
                }),
            Action::make('label')->label(__('used_tyres.actions.label'))->icon(Heroicon::OutlinedQrCode)->color('gray')
                ->url(route('used-tyres.label', $tyre))->openUrlInNewTab(),
            EditAction::make(),
            Action::make('scrap')->label(__('used_tyres.actions.scrap'))->icon(Heroicon::OutlinedTrash)->color('danger')
                ->visible(! in_array($tyre->status, [UsedTyreStatus::Sold, UsedTyreStatus::Scrapped], true)
                    && $user->can(Permission::UsedTyresManage->value) && $user->canAccessSite($tyre->site_id))
                ->requiresConfirmation()
                ->schema([Textarea::make('reason')->label(__('used_tyres.fields.scrap_reason'))->required()])
                ->action(function (array $data) use ($tyre) {
                    try {
                        app(UsedTyreActions::class)->scrap($tyre, auth()->user(), $data['reason']);
                        Notification::make()->success()->title(__('used_tyres.messages.scrapped'))->send();
                        $this->redirect(UsedTyreResource::getUrl('view', ['record' => $tyre]));
                    } catch (BusinessRuleException $e) {
                        Notification::make()->danger()->title($e->getMessage())->send();
                    }
                }),
        ];
    }
}
