<?php

namespace App\Filament\Resources\InventoryCounts;

use App\Enums\CategoryType;
use App\Enums\InventoryCountStatus;
use App\Filament\Resources\InventoryCounts\Pages\CountInventory;
use App\Filament\Resources\InventoryCounts\Pages\CreateInventoryCount;
use App\Filament\Resources\InventoryCounts\Pages\ListInventoryCounts;
use App\Filament\Support\PageHub;
use App\Models\Category;
use App\Models\InventoryCount;
use App\Models\Site;
use BackedEnum;
use Filament\Actions\Action;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;

/** Inventory counts (§6.4): count on tablet/phone, owner validation, adjustments. */
class InventoryCountResource extends Resource
{
    public static function shouldRegisterNavigation(): bool
    {
        return PageHub::inMenu(static::class) && parent::shouldRegisterNavigation();
    }

    protected static ?string $model = InventoryCount::class;

    /** French labels keep their own capitalisation ("Pneus d'occasion"). */
    protected static bool $hasTitleCaseModelLabel = false;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedClipboardDocumentList;

    protected static ?int $navigationSort = 60;

    protected static ?string $recordTitleAttribute = 'number';

    public static function getModelLabel(): string
    {
        return __('inventory.label');
    }

    public static function getPluralModelLabel(): string
    {
        return __('inventory.plural');
    }

    public static function getNavigationGroup(): ?string
    {
        return __('app.navigation.stock');
    }

    public static function getEloquentQuery(): Builder
    {
        return parent::getEloquentQuery()->visibleTo(auth()->user())->with(['site', 'category']);
    }

    public static function form(Schema $schema): Schema
    {
        return $schema->columns(2)->components([
            Select::make('site_id')->label(__('inventory.fields.site'))
                ->options(fn () => Site::query()->visibleTo(auth()->user())->where('is_active', true)->orderBy('code')->pluck('name', 'id'))
                ->default(fn () => auth()->user()->default_site_id)->required(),
            Select::make('category_id')->label(__('inventory.fields.category'))
                ->options(fn () => Category::query()->with('parent')
                    ->whereNotIn('type', [CategoryType::Service->value, CategoryType::Bundle->value, CategoryType::UsedTyre->value])
                    ->get()->mapWithKeys(fn (Category $c) => [$c->id => $c->fullName()])),
            Textarea::make('notes')->label(__('inventory.fields.notes'))->columnSpanFull(),
        ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->defaultSort('id', 'desc')
            ->modifyQueryUsing(fn (Builder $query) => $query
                ->withCount(['lines', 'lines as counted_count' => fn ($l) => $l->whereNotNull('counted_quantity')]))
            ->columns([
                TextColumn::make('number')->label(__('inventory.fields.number'))->searchable()->weight('bold'),
                TextColumn::make('site.code')->label(__('inventory.fields.site'))->badge(),
                TextColumn::make('category.name')->label(__('catalog.fields.category'))->placeholder('—'),
                TextColumn::make('status')->label(__('inventory.fields.status'))->badge()
                    ->color(fn (InventoryCountStatus $state) => match ($state) {
                        InventoryCountStatus::Draft => 'info', InventoryCountStatus::Submitted => 'warning',
                        InventoryCountStatus::Approved => 'success', InventoryCountStatus::Cancelled => 'gray',
                    }),
                TextColumn::make('progress')->label(__('inventory.fields.progress'))
                    ->state(fn (InventoryCount $record) => $record->counted_count.' / '.$record->lines_count),
                TextColumn::make('created_at')->label(__('inventory.fields.created_at'))->dateTime('d/m/Y H:i')->sortable(),
            ])
            ->filters([SelectFilter::make('status')->label(__('inventory.fields.status'))->options(InventoryCountStatus::class)])
            ->recordUrl(fn (InventoryCount $record) => static::getUrl('count', ['record' => $record]))
            ->recordActions([
                Action::make('open')->label(__('inventory.actions.count'))->icon(Heroicon::OutlinedPencilSquare)
                    ->url(fn (InventoryCount $record) => static::getUrl('count', ['record' => $record])),
            ]);
    }

    public static function getPages(): array
    {
        return [
            'index' => ListInventoryCounts::route('/'),
            'create' => CreateInventoryCount::route('/create'),
            'count' => CountInventory::route('/{record}'),
        ];
    }
}
