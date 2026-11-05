<?php

namespace App\Filament\Resources\ReplenishmentRequests;

use App\Enums\ReplenishmentStatus;
use App\Filament\Resources\ReplenishmentRequests\Pages\CreateReplenishmentRequest;
use App\Filament\Resources\ReplenishmentRequests\Pages\ListReplenishmentRequests;
use App\Filament\Resources\ReplenishmentRequests\Pages\ViewReplenishmentRequest;
use App\Filament\Resources\Transfers\TransferResource;
use App\Models\ReplenishmentRequest;
use App\Models\Site;
use BackedEnum;
use Filament\Actions\ViewAction;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Infolists\Components\RepeatableEntry;
use Filament\Infolists\Components\TextEntry;
use Filament\Resources\Resource;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;

/** A store asks the depot for goods; the depot turns it into a transfer (§2, §6.3). */
class ReplenishmentRequestResource extends Resource
{
    protected static ?string $model = ReplenishmentRequest::class;

    /** French labels keep their own capitalisation ("Pneus d'occasion"). */
    protected static bool $hasTitleCaseModelLabel = false;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedClipboardDocumentCheck;

    protected static ?int $navigationSort = 50;

    protected static ?string $recordTitleAttribute = 'number';

    public static function getModelLabel(): string
    {
        return __('transfers.requests.label');
    }

    public static function getPluralModelLabel(): string
    {
        return __('transfers.requests.plural');
    }

    public static function getNavigationGroup(): ?string
    {
        return __('app.navigation.stock');
    }

    public static function getNavigationBadge(): ?string
    {
        $count = static::getEloquentQuery()->where('status', ReplenishmentStatus::Pending->value)->count();

        return $count > 0 ? (string) $count : null;
    }

    public static function getEloquentQuery(): Builder
    {
        return parent::getEloquentQuery()->visibleTo(auth()->user())->with(['site', 'sourceSite', 'requester']);
    }

    public static function form(Schema $schema): Schema
    {
        return $schema->components([
            Section::make()->columns(2)->schema([
                Select::make('site_id')->label(__('transfers.fields.site'))
                    ->options(fn () => Site::query()->visibleTo(auth()->user())->where('is_active', true)->orderBy('code')->pluck('name', 'id'))
                    ->default(fn () => auth()->user()->default_site_id)->required(),
                Select::make('source_site_id')->label(__('transfers.fields.source_site'))
                    ->options(fn () => Site::query()->where('is_active', true)->orderBy('code')->pluck('name', 'id'))
                    ->default(fn () => Site::query()->where('code', 'DEP')->value('id'))->required()->different('site_id'),
            ]),
            Section::make(__('transfers.fields.lines'))->schema([TransferResource::linesRepeater('lines')]),
            Section::make(__('app.more_details'))->collapsed()->schema([
                Textarea::make('notes')->label(__('transfers.fields.notes')),
            ]),
        ]);
    }

    public static function infolist(Schema $schema): Schema
    {
        return $schema->components([
            Section::make()->columns(4)->schema([
                TextEntry::make('number')->label(__('transfers.fields.number'))->weight('bold'),
                TextEntry::make('status')->label(__('transfers.fields.status'))->badge(),
                TextEntry::make('site.name')->label(__('transfers.fields.site')),
                TextEntry::make('sourceSite.name')->label(__('transfers.fields.source_site')),
                TextEntry::make('requester.name')->label(__('transfers.fields.requested_by')),
                TextEntry::make('created_at')->label(__('inventory.fields.created_at'))->dateTime('d/m/Y H:i'),
                TextEntry::make('transfer.number')->label(__('transfers.label'))->placeholder('—')
                    ->url(fn (ReplenishmentRequest $record) => $record->transfer ? TransferResource::getUrl('view', ['record' => $record->transfer]) : null),
                TextEntry::make('notes')->label(__('transfers.fields.notes'))->placeholder('—'),
            ]),
            Section::make(__('transfers.fields.lines'))->schema([
                RepeatableEntry::make('lines')->hiddenLabel()->columns(2)->schema([
                    TextEntry::make('product.name')->label(__('transfers.fields.product')),
                    TextEntry::make('quantity')->label(__('transfers.fields.quantity')),
                ]),
            ]),
        ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->defaultSort('id', 'desc')
            ->modifyQueryUsing(fn (Builder $query) => $query->withCount('lines'))
            ->columns([
                TextColumn::make('number')->label(__('transfers.fields.number'))->searchable()->weight('bold'),
                TextColumn::make('site.code')->label(__('transfers.fields.site'))->badge(),
                TextColumn::make('sourceSite.code')->label(__('transfers.fields.source_site'))->badge(),
                TextColumn::make('status')->label(__('transfers.fields.status'))->badge()
                    ->color(fn (ReplenishmentStatus $state) => match ($state) {
                        ReplenishmentStatus::Pending => 'warning', ReplenishmentStatus::Converted => 'success', ReplenishmentStatus::Cancelled => 'gray',
                    }),
                TextColumn::make('lines_count')->label(__('transfers.fields.lines'))->alignCenter(),
                TextColumn::make('requester.name')->label(__('transfers.fields.requested_by'))->toggleable(),
                TextColumn::make('created_at')->label(__('inventory.fields.created_at'))->dateTime('d/m/Y H:i')->sortable(),
            ])
            ->filters([SelectFilter::make('status')->label(__('transfers.fields.status'))->options(ReplenishmentStatus::class)])
            ->recordActions([ViewAction::make()]);
    }

    public static function getPages(): array
    {
        return [
            'index' => ListReplenishmentRequests::route('/'),
            'create' => CreateReplenishmentRequest::route('/create'),
            'view' => ViewReplenishmentRequest::route('/{record}'),
        ];
    }
}
