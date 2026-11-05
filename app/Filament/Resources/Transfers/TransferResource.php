<?php

namespace App\Filament\Resources\Transfers;

use App\Enums\ProductKind;
use App\Enums\TransferStatus;
use App\Filament\Resources\Transfers\Pages\CreateTransfer;
use App\Filament\Resources\Transfers\Pages\ListTransfers;
use App\Filament\Resources\Transfers\Pages\ViewTransfer;
use App\Models\Product;
use App\Models\Site;
use App\Models\Transfer;
use App\Models\TransferLine;
use BackedEnum;
use Filament\Actions\ViewAction;
use Filament\Forms\Components\Repeater;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
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

/** Inter-site transfers (D-11): in transit → received / cancelled. */
class TransferResource extends Resource
{
    protected static ?string $model = Transfer::class;

    /** French labels keep their own capitalisation ("Pneus d'occasion"). */
    protected static bool $hasTitleCaseModelLabel = false;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedTruck;

    protected static ?int $navigationSort = 40;

    protected static ?string $recordTitleAttribute = 'number';

    public static function getModelLabel(): string
    {
        return __('transfers.label');
    }

    public static function getPluralModelLabel(): string
    {
        return __('transfers.plural');
    }

    public static function getNavigationGroup(): ?string
    {
        return __('app.navigation.stock');
    }

    public static function getNavigationBadge(): ?string
    {
        $count = static::getEloquentQuery()->where('status', TransferStatus::InTransit->value)->count();

        return $count > 0 ? (string) $count : null;
    }

    public static function getEloquentQuery(): Builder
    {
        return parent::getEloquentQuery()->visibleTo(auth()->user())->with(['fromSite', 'toSite']);
    }

    public static function form(Schema $schema): Schema
    {
        return $schema->components([
            Section::make()->columns(2)->schema([
                Select::make('from_site_id')->label(__('transfers.fields.from'))
                    ->options(fn () => Site::query()->visibleTo(auth()->user())->where('is_active', true)->orderBy('code')->pluck('name', 'id'))
                    ->default(fn () => Site::query()->where('code', 'DEP')->value('id'))
                    ->required()->live(),
                Select::make('to_site_id')->label(__('transfers.fields.to'))
                    ->options(fn () => Site::query()->where('is_active', true)->orderBy('code')->pluck('name', 'id'))
                    ->required()->different('from_site_id'),
            ]),
            Section::make(__('transfers.fields.lines'))->schema([
                self::linesRepeater('lines'),
            ]),
            Section::make(__('app.more_details'))->collapsed()->schema([
                Textarea::make('notes')->label(__('transfers.fields.notes')),
            ]),
        ]);
    }

    public static function linesRepeater(string $name): Repeater
    {
        return Repeater::make($name)->hiddenLabel()
            ->schema([
                Select::make('product_id')->label(__('transfers.fields.product'))
                    ->options(fn () => Product::query()->where('kind', ProductKind::Goods->value)->where('is_active', true)->orderBy('name')->pluck('name', 'id'))
                    ->searchable()->required()->distinct()->columnSpan(3),
                TextInput::make('quantity')->label(__('transfers.fields.quantity'))->integer()->minValue(1)->required(),
            ])
            ->columns(4)->minItems(1)->defaultItems(1)->addActionLabel(__('transfers.actions.add_line'));
    }

    public static function infolist(Schema $schema): Schema
    {
        return $schema->components([
            Section::make()->columns(4)->schema([
                TextEntry::make('number')->label(__('transfers.fields.number'))->weight('bold'),
                TextEntry::make('status')->label(__('transfers.fields.status'))->badge(),
                TextEntry::make('fromSite.name')->label(__('transfers.fields.from')),
                TextEntry::make('toSite.name')->label(__('transfers.fields.to')),
                TextEntry::make('sent_at')->label(__('transfers.fields.sent_at'))->dateTime('d/m/Y H:i'),
                TextEntry::make('sender.name')->label(__('transfers.fields.sent_by')),
                TextEntry::make('received_at')->label(__('transfers.fields.received_at'))->dateTime('d/m/Y H:i')->placeholder('—'),
                TextEntry::make('receiver.name')->label(__('transfers.fields.received_by'))->placeholder('—'),
                TextEntry::make('notes')->label(__('transfers.fields.notes'))->placeholder('—')->columnSpanFull(),
            ]),
            Section::make(__('transfers.fields.lines'))->schema([
                RepeatableEntry::make('lines')->hiddenLabel()->columns(4)->schema([
                    TextEntry::make('product.name')->label(__('transfers.fields.product'))->columnSpan(1),
                    TextEntry::make('quantity_sent')->label(__('transfers.fields.quantity_sent')),
                    TextEntry::make('quantity_received')->label(__('transfers.fields.quantity_received'))->placeholder('—')
                        ->color(fn (TransferLine $record) => $record->quantity_received !== null && $record->quantity_received < $record->quantity_sent ? 'danger' : null),
                    TextEntry::make('discrepancy_reason')->label(__('transfers.fields.discrepancy_reason'))->placeholder('—'),
                ]),
            ]),
        ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->modifyQueryUsing(fn (Builder $query) => $query->withCount('lines'))
            ->defaultSort('id', 'desc')
            ->columns([
                TextColumn::make('number')->label(__('transfers.fields.number'))->searchable()->weight('bold'),
                TextColumn::make('fromSite.code')->label(__('transfers.fields.from'))->badge(),
                TextColumn::make('toSite.code')->label(__('transfers.fields.to'))->badge(),
                TextColumn::make('status')->label(__('transfers.fields.status'))->badge()
                    ->color(fn (TransferStatus $state) => match ($state) {
                        TransferStatus::InTransit => 'warning', TransferStatus::Received => 'success', TransferStatus::Cancelled => 'gray',
                    }),
                TextColumn::make('lines_count')->label(__('transfers.fields.lines'))->alignCenter(),
                TextColumn::make('sent_at')->label(__('transfers.fields.sent_at'))->dateTime('d/m/Y H:i')->sortable(),
                TextColumn::make('received_at')->label(__('transfers.fields.received_at'))->dateTime('d/m/Y H:i')->placeholder('—')->toggleable(),
            ])
            ->filters([
                SelectFilter::make('status')->label(__('transfers.fields.status'))->options(TransferStatus::class),
                SelectFilter::make('to_site_id')->label(__('transfers.fields.to'))->options(fn () => Site::query()->orderBy('code')->pluck('name', 'id')),
            ])
            ->recordActions([ViewAction::make()]);
    }

    public static function getPages(): array
    {
        return [
            'index' => ListTransfers::route('/'),
            'create' => CreateTransfer::route('/create'),
            'view' => ViewTransfer::route('/{record}'),
        ];
    }
}
