<?php

namespace App\Filament\Resources\Services;

use App\Enums\CategoryType;
use App\Enums\Permission;
use App\Enums\ProductKind;
use App\Filament\Resources\Services\Pages\CreateService;
use App\Filament\Resources\Services\Pages\EditService;
use App\Filament\Resources\Services\Pages\ListServices;
use App\Filament\Support\Fields;
use App\Models\Category;
use App\Models\Product;
use BackedEnum;
use Filament\Actions\EditAction;
use Filament\Forms\Components\Repeater;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Resources\Resource;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\IconColumn;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;

/** Workshop services (§5.9): price fixed or by rim diameter, automatic consumables. */
class ServiceResource extends Resource
{
    /** Simplicity (§5): setup pages appear in the menu only for those who manage them. */
    public static function shouldRegisterNavigation(): bool
    {
        return parent::shouldRegisterNavigation() && (auth()->user()?->canAny([Permission::ProductsManage->value]) ?? false);
    }

    protected static ?string $model = Product::class;

    /** French labels keep their own capitalisation ("Pneus d'occasion"). */
    protected static bool $hasTitleCaseModelLabel = false;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedWrenchScrewdriver;

    protected static ?int $navigationSort = 20;

    protected static ?string $slug = 'services';

    public static function getModelLabel(): string
    {
        return __('catalog.services.label');
    }

    public static function getPluralModelLabel(): string
    {
        return __('catalog.services.plural');
    }

    public static function getNavigationGroup(): ?string
    {
        return __('app.navigation.catalog');
    }

    public static function getEloquentQuery(): Builder
    {
        return parent::getEloquentQuery()->where('kind', ProductKind::Service->value);
    }

    public static function form(Schema $schema): Schema
    {
        return $schema->components([
            Section::make()->columns(2)->schema([
                TextInput::make('name')->label(__('catalog.categories.fields.name'))->required()->maxLength(255),
                TextInput::make('name_ar')->label(__('catalog.fields.name_ar'))->maxLength(255),
                Select::make('category_id')->label(__('catalog.fields.category'))
                    ->options(fn () => Category::query()->with('parent')->where('type', CategoryType::Service->value)->get()
                        ->mapWithKeys(fn (Category $c) => [$c->id => $c->fullName()]))
                    ->required(),
                Fields::money('price_retail_cents', __('catalog.fields.price'))->required(),
                TextInput::make('duration_minutes')->label(__('catalog.fields.duration'))->integer()->minValue(1),
                Toggle::make('is_active')->label(__('catalog.fields.is_active'))->default(true),
            ]),
            Section::make(__('app.more_details'))->collapsed()->schema([
                Select::make('sites')->label(__('catalog.fields.sites'))->relationship('sites', 'name')->multiple()->preload(),
                Repeater::make('priceTiers')->label(__('catalog.fields.price_tiers'))->relationship()
                    ->schema([
                        TextInput::make('min_rim_diameter')->label(__('catalog.fields.min_rim'))->numeric()->minValue(8)->maxValue(26)->required(),
                        TextInput::make('max_rim_diameter')->label(__('catalog.fields.max_rim'))->numeric()->minValue(8)->maxValue(26),
                        Fields::money('price_cents', __('catalog.fields.price'))->required(),
                    ])->columns(3)->defaultItems(0),
                Repeater::make('consumables')->label(__('catalog.fields.consumables'))->relationship()
                    ->schema([
                        Select::make('product_id')->label(__('catalog.fields.name'))
                            ->options(fn () => Product::query()->where('kind', ProductKind::Goods->value)->where('is_active', true)->orderBy('name')->pluck('name', 'id'))
                            ->searchable()->required(),
                        TextInput::make('quantity')->label(__('catalog.fields.quantity'))->integer()->minValue(1)->default(1)->required(),
                    ])->columns(2)->defaultItems(0),
                Textarea::make('description')->label(__('catalog.fields.description')),
            ]),
        ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->modifyQueryUsing(fn (Builder $query) => $query->with(['category.parent', 'priceTiers']))
            ->defaultSort('name')
            ->columns([
                TextColumn::make('sku')->label(__('catalog.fields.sku'))->toggleable(),
                TextColumn::make('name')->label(__('catalog.fields.name'))
                    ->formatStateUsing(fn (Product $r) => $r->displayName())->searchable(['name', 'name_ar'])->weight('bold'),
                TextColumn::make('category.name')->label(__('catalog.fields.category'))->formatStateUsing(fn (Product $r) => $r->category->fullName()),
                Fields::moneyColumn('price_retail_cents', __('catalog.fields.price'))
                    ->description(fn (Product $r) => $r->priceTiers->isNotEmpty() ? __('catalog.fields.price_tiers').' ('.$r->priceTiers->count().')' : null),
                TextColumn::make('duration_minutes')->label(__('catalog.fields.duration'))->placeholder('—'),
                IconColumn::make('is_active')->label(__('catalog.fields.is_active'))->boolean(),
            ])
            ->recordActions([EditAction::make()]);
    }

    public static function getPages(): array
    {
        return [
            'index' => ListServices::route('/'),
            'create' => CreateService::route('/create'),
            'edit' => EditService::route('/{record}/edit'),
        ];
    }
}
