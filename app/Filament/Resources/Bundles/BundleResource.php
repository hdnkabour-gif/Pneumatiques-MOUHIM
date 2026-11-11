<?php

namespace App\Filament\Resources\Bundles;

use App\Enums\CategoryType;
use App\Enums\Permission;
use App\Enums\ProductKind;
use App\Filament\Resources\Bundles\Pages\CreateBundle;
use App\Filament\Resources\Bundles\Pages\EditBundle;
use App\Filament\Resources\Bundles\Pages\ListBundles;
use App\Filament\Support\Fields;
use App\Filament\Support\PageHub;
use App\Models\Category;
use App\Models\Product;
use App\Services\Catalog\BundleExpander;
use App\Support\Decimal;
use App\Support\Money;
use BackedEnum;
use Closure;
use Filament\Actions\EditAction;
use Filament\Forms\Components\Repeater;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Resources\Resource;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\IconColumn;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;

/** Bundles (§5.9): products + services at a global price, decomposed on invoices and in stock. */
class BundleResource extends Resource
{
    /** Simplicity (§5): setup pages appear in the menu only for those who manage them. */
    public static function shouldRegisterNavigation(): bool
    {
        return PageHub::inMenu(static::class) && parent::shouldRegisterNavigation() && (auth()->user()?->canAny([Permission::ProductsManage->value]) ?? false);
    }

    protected static ?string $model = Product::class;

    /** French labels keep their own capitalisation ("Pneus d'occasion"). */
    protected static bool $hasTitleCaseModelLabel = false;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedGift;

    protected static ?int $navigationSort = 30;

    protected static ?string $slug = 'bundles';

    public static function getModelLabel(): string
    {
        return __('catalog.bundles.label');
    }

    public static function getPluralModelLabel(): string
    {
        return __('catalog.bundles.plural');
    }

    public static function getNavigationGroup(): ?string
    {
        return __('app.navigation.catalog');
    }

    public static function getEloquentQuery(): Builder
    {
        return parent::getEloquentQuery()->where('kind', ProductKind::Bundle->value);
    }

    public static function form(Schema $schema): Schema
    {
        return $schema->components([
            Section::make()->columns(2)->schema([
                TextInput::make('name')->label(__('catalog.fields.name'))->required()->maxLength(255),
                TextInput::make('name_ar')->label(__('catalog.fields.name_ar'))->maxLength(255),
                Fields::money('price_retail_cents', __('catalog.fields.bundle_total'))->required()
                    ->rule(fn (Get $get) => function (string $attribute, $value, Closure $fail) use ($get) {
                        $items = collect($get('bundleItems') ?? [])->map(fn ($i) => [
                            'quantity' => (int) ($i['quantity'] ?? 0),
                            'unit_price_cents' => self::cents($i['unit_price_cents'] ?? null),
                        ]);
                        if ($items->isEmpty()) {
                            $fail(__('catalog.errors.bundle_needs_items'));

                            return;
                        }
                        $sum = BundleExpander::total($items);
                        $price = self::cents($value);
                        if ($sum !== $price) {
                            $fail(__('catalog.errors.bundle_total_mismatch', ['sum' => Money::format($sum), 'price' => Money::format($price)]));
                        }
                    }),
                Toggle::make('is_active')->label(__('catalog.fields.is_active'))->default(true),
            ]),
            Section::make(__('catalog.fields.items'))->schema([
                Repeater::make('bundleItems')->hiddenLabel()->relationship()
                    ->orderColumn('sort_order')
                    ->schema([
                        Select::make('product_id')->label(__('catalog.fields.name'))
                            ->options(fn () => Product::query()->whereIn('kind', [ProductKind::Goods->value, ProductKind::Service->value])
                                ->where('is_active', true)->orderBy('name')->pluck('name', 'id'))
                            ->searchable()->required()->columnSpan(2),
                        TextInput::make('quantity')->label(__('catalog.fields.quantity'))->integer()->minValue(1)->default(1)->required(),
                        Fields::money('unit_price_cents', __('catalog.fields.allocated_price'))->required(),
                    ])->columns(4)->minItems(1)->defaultItems(1),
            ]),
            Section::make(__('app.more_details'))->collapsed()->schema([
                Textarea::make('description')->label(__('catalog.fields.description')),
            ]),
        ]);
    }

    /** Raw form state ("1 200,50" or already cents) to cents. */
    private static function cents(mixed $value): int
    {
        if ($value === null || $value === '') {
            return 0;
        }

        return is_int($value) ? $value : Decimal::toCents((string) $value);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->modifyQueryUsing(fn (Builder $query) => $query->withCount('bundleItems'))
            ->defaultSort('name')
            ->columns([
                TextColumn::make('sku')->label(__('catalog.fields.sku'))->toggleable(),
                TextColumn::make('name')->label(__('catalog.fields.name'))->formatStateUsing(fn (Product $r) => $r->displayName())->searchable(['name', 'name_ar'])->weight('bold'),
                TextColumn::make('bundle_items_count')->label(__('catalog.fields.items'))->badge(),
                Fields::moneyColumn('price_retail_cents', __('catalog.fields.bundle_total')),
                IconColumn::make('is_active')->label(__('catalog.fields.is_active'))->boolean(),
            ])
            ->recordActions([EditAction::make()]);
    }

    public static function bundleCategoryId(): int
    {
        return (int) Category::query()->where('type', CategoryType::Bundle->value)->value('id');
    }

    public static function getPages(): array
    {
        return [
            'index' => ListBundles::route('/'),
            'create' => CreateBundle::route('/create'),
            'edit' => EditBundle::route('/{record}/edit'),
        ];
    }
}
