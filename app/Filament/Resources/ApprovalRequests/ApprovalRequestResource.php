<?php

namespace App\Filament\Resources\ApprovalRequests;

use App\Enums\ApprovalStatus;
use App\Enums\ApprovalType;
use App\Enums\Permission;
use App\Filament\Resources\ApprovalRequests\Pages\ListApprovalRequests;
use App\Filament\Resources\ApprovalRequests\Pages\ViewApprovalRequest;
use App\Models\ApprovalRequest;
use App\Services\Approvals\ApprovalService;
use BackedEnum;
use Filament\Actions\ViewAction;
use Filament\Infolists\Components\TextEntry;
use Filament\Resources\Resource;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;

/** Approval inbox (§4): approvers see everything, requesters see their own requests. */
class ApprovalRequestResource extends Resource
{
    protected static ?string $model = ApprovalRequest::class;

    /** French labels keep their own capitalisation ("Pneus d'occasion"). */
    protected static bool $hasTitleCaseModelLabel = false;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedHandRaised;

    protected static ?int $navigationSort = 1;

    public static function getModelLabel(): string
    {
        return __('approvals.label');
    }

    public static function getPluralModelLabel(): string
    {
        return __('approvals.plural');
    }

    public static function getNavigationBadge(): ?string
    {
        $count = static::getEloquentQuery()->where('status', ApprovalStatus::Pending->value)->count();

        return $count > 0 ? (string) $count : null;
    }

    public static function getNavigationBadgeColor(): ?string
    {
        return 'danger';
    }

    public static function getEloquentQuery(): Builder
    {
        $user = auth()->user();

        return parent::getEloquentQuery()
            ->with(['requester', 'decider', 'site'])
            ->when(! $user->can(Permission::ApprovalsDecide->value), fn (Builder $query) => $query->where('requested_by', $user->id));
    }

    public static function summary(ApprovalRequest $record): string
    {
        try {
            return app(ApprovalService::class)->handler($record->type)->summary($record);
        } catch (\LogicException) {
            return $record->type->label();
        }
    }

    public static function infolist(Schema $schema): Schema
    {
        return $schema->components([
            Section::make()->columns(3)->schema([
                TextEntry::make('type')->label(__('approvals.fields.type'))->badge(),
                TextEntry::make('status')->label(__('approvals.fields.status'))->badge(),
                TextEntry::make('site.name')->label(__('approvals.fields.site'))->placeholder('—'),
                TextEntry::make('summary')->label(__('approvals.fields.summary'))->state(fn (ApprovalRequest $record) => static::summary($record))->columnSpanFull()->weight('bold'),
                TextEntry::make('reason')->label(__('approvals.fields.reason'))->columnSpanFull(),
                TextEntry::make('requester.name')->label(__('approvals.fields.requested_by')),
                TextEntry::make('created_at')->label(__('approvals.fields.created_at'))->dateTime('d/m/Y H:i'),
                TextEntry::make('decider.name')->label(__('approvals.fields.decided_by'))->placeholder('—'),
                TextEntry::make('decided_at')->label(__('approvals.fields.decided_at'))->dateTime('d/m/Y H:i')->placeholder('—'),
                TextEntry::make('decided_via')->label(__('approvals.fields.decided_via'))
                    ->formatStateUsing(fn (?string $state) => $state ? __("approvals.via.{$state}") : '—')->placeholder('—'),
                TextEntry::make('decision_comment')->label(__('approvals.fields.comment'))->placeholder('—'),
            ]),
        ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->defaultSort('id', 'desc')
            ->columns([
                TextColumn::make('created_at')->label(__('approvals.fields.created_at'))->dateTime('d/m/Y H:i')->sortable(),
                TextColumn::make('type')->label(__('approvals.fields.type'))->badge(),
                TextColumn::make('summary')->label(__('approvals.fields.summary'))->wrap()
                    ->state(fn (ApprovalRequest $record) => static::summary($record))
                    ->description(fn (ApprovalRequest $record) => $record->reason),
                TextColumn::make('site.code')->label(__('approvals.fields.site'))->badge()->placeholder('—'),
                TextColumn::make('requester.name')->label(__('approvals.fields.requested_by')),
                TextColumn::make('status')->label(__('approvals.fields.status'))->badge()
                    ->color(fn (ApprovalStatus $state) => match ($state) {
                        ApprovalStatus::Pending => 'warning', ApprovalStatus::Approved => 'success',
                        ApprovalStatus::Rejected => 'danger', ApprovalStatus::Cancelled => 'gray',
                    }),
            ])
            ->filters([
                SelectFilter::make('status')->label(__('approvals.fields.status'))->options(ApprovalStatus::class)->default(ApprovalStatus::Pending->value),
                SelectFilter::make('type')->label(__('approvals.fields.type'))->options(ApprovalType::class),
            ])
            ->recordActions([ViewAction::make()]);
    }

    public static function getPages(): array
    {
        return [
            'index' => ListApprovalRequests::route('/'),
            'view' => ViewApprovalRequest::route('/{record}'),
        ];
    }
}
