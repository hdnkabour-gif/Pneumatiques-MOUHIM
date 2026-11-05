<?php

namespace App\Filament\Resources\Products;

use App\Enums\BatteryTechnology;
use App\Enums\CategoryType;
use App\Enums\FilterType;
use App\Enums\OilType;
use App\Enums\TyreSeason;
use App\Enums\TyreUsage;
use App\Enums\Unit;
use App\Filament\Support\Fields;
use App\Models\Category;
use App\Models\Product;
use App\Support\Money;
use Filament\Forms\Components\Repeater;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Schemas\Components\Component;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Components\Text;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Schemas\Schema;

/** Goods form: common fields, then the technical fields of the selected category (§5.2–5.8). */
class ProductForm
{
    public static function configure(Schema $schema): Schema
    {
        return $schema->components([
            Section::make(__('catalog.sections.main'))->columns(2)->schema([
                Select::make('category_id')->label(__('catalog.fields.category'))
                    ->options(fn () => self::categoryOptions())
                    ->required()->searchable()->live(),
                TextInput::make('name')->label(__('catalog.fields.name'))->maxLength(255)
                    ->required(fn (Get $get) => self::type($get) !== CategoryType::NewTyre)
                    ->helperText(fn (Get $get) => self::type($get) === CategoryType::NewTyre ? __('catalog.help.tyre_name') : null),
                Select::make('brand_id')->label(__('catalog.fields.brand'))
                    ->relationship('brand', 'name')->searchable()->preload()
                    ->createOptionForm([TextInput::make('name')->label(__('catalog.brands.fields.name'))->required()->unique('brands', 'name')]),
                TextInput::make('manufacturer_ref')->label(__('catalog.fields.manufacturer_ref'))->maxLength(100),
            ]),

            Section::make(__('catalog.sections.prices'))->columns(3)->schema([
                Fields::money('price_retail_cents', __('catalog.fields.price_retail'))->required(),
                Fields::money('price_pro_cents', __('catalog.fields.price_pro')),
                Fields::money('price_reseller_cents', __('catalog.fields.price_reseller')),
                Fields::money('reference_cost_cents', __('catalog.fields.reference_cost'))->visible(fn () => Fields::canSeeCosts()),
                Text::make(fn (?Product $record) => __('catalog.fields.average_cost').' : '.Money::format($record?->average_cost_cents))
                    ->visible(fn (?Product $record) => $record !== null && Fields::canSeeCosts()),
                Text::make(fn (?Product $record) => __('catalog.fields.suggested_price').' : '.(($p = $record?->suggestedPriceCents()) === null ? __('catalog.fields.margin_missing') : Money::format($p)))
                    ->visible(fn (?Product $record) => $record !== null && Fields::canSeeCosts()),
            ]),

            self::specSection('tyreSpec', CategoryType::NewTyre, __('catalog.sections.tyre'), [
                TextInput::make('width')->label(__('catalog.fields.width'))->integer()->minValue(100)->maxValue(455)->required(),
                TextInput::make('aspect_ratio')->label(__('catalog.fields.aspect_ratio'))->integer()->minValue(10)->maxValue(95),
                TextInput::make('rim_diameter')->label(__('catalog.fields.rim_diameter'))->numeric()->minValue(8)->maxValue(26)->required(),
                TextInput::make('load_index')->label(__('catalog.fields.load_index'))->maxLength(10),
                TextInput::make('speed_index')->label(__('catalog.fields.speed_index'))->maxLength(3)
                    ->dehydrateStateUsing(fn ($state) => $state ? strtoupper($state) : null),
                Select::make('season')->label(__('catalog.fields.season'))->options(TyreSeason::class),
                Select::make('usage')->label(__('catalog.fields.usage'))->options(TyreUsage::class),
                TextInput::make('pattern')->label(__('catalog.fields.pattern'))->maxLength(255),
                Toggle::make('is_xl')->label(__('catalog.fields.is_xl')),
                Toggle::make('is_run_flat')->label(__('catalog.fields.is_run_flat')),
                Toggle::make('is_reinforced')->label(__('catalog.fields.is_reinforced')),
                TextInput::make('oe_marking')->label(__('catalog.fields.oe_marking'))->maxLength(50),
                TextInput::make('origin_country')->label(__('catalog.fields.origin_country'))->maxLength(50),
                TextInput::make('warranty_months')->label(__('catalog.fields.warranty_months'))->integer()->minValue(0),
            ]),
            self::specSection('oilSpec', CategoryType::Oil, __('catalog.sections.oil'), [
                TextInput::make('viscosity')->label(__('catalog.fields.viscosity'))->maxLength(20)
                    ->dehydrateStateUsing(fn ($state) => $state ? strtoupper(str_replace(['-', ' '], '', $state)) : null),
                Select::make('oil_type')->label(__('catalog.fields.oil_type'))->options(OilType::class),
                TextInput::make('volume_ml')->label(__('catalog.fields.volume_ml'))->integer()->minValue(1),
                TextInput::make('standards')->label(__('catalog.fields.standards'))->maxLength(255),
                TextInput::make('oem_approvals')->label(__('catalog.fields.oem_approvals'))->maxLength(255),
            ]),
            self::specSection('batterySpec', CategoryType::Battery, __('catalog.sections.battery'), [
                TextInput::make('voltage')->label(__('catalog.fields.voltage'))->integer()->default(12),
                TextInput::make('capacity_ah')->label(__('catalog.fields.capacity_ah'))->integer(),
                TextInput::make('cca')->label(__('catalog.fields.cca'))->integer(),
                TextInput::make('dimensions')->label(__('catalog.fields.dimensions'))->maxLength(50),
                TextInput::make('polarity')->label(__('catalog.fields.polarity'))->maxLength(10),
                Select::make('technology')->label(__('catalog.fields.technology'))->options(BatteryTechnology::class),
                TextInput::make('warranty_months')->label(__('catalog.fields.warranty_months'))->integer()->minValue(0),
            ]),
            self::specSection('filterSpec', CategoryType::Filter, __('catalog.sections.filter'), [
                Select::make('filter_type')->label(__('catalog.fields.filter_type'))->options(FilterType::class),
            ]),
            self::specSection('lightingSpec', CategoryType::Lighting, __('catalog.sections.lighting'), [
                TextInput::make('base')->label(__('catalog.fields.base'))->maxLength(20)
                    ->dehydrateStateUsing(fn ($state) => $state ? strtoupper($state) : null),
                TextInput::make('power_w')->label(__('catalog.fields.power_w'))->integer(),
                TextInput::make('voltage')->label(__('catalog.fields.voltage'))->integer(),
                TextInput::make('usage')->label(__('catalog.fields.lighting_usage'))->maxLength(30),
            ]),

            Section::make(__('app.more_details'))->collapsed()->columns(2)->schema([
                Select::make('unit')->label(__('catalog.fields.unit'))->options(Unit::class)->default(Unit::Piece)->required(),
                Toggle::make('is_active')->label(__('catalog.fields.is_active'))->default(true),
                Textarea::make('description')->label(__('catalog.fields.description'))->columnSpanFull(),
                Repeater::make('references')->label(__('catalog.fields.references'))->relationship()
                    ->schema([
                        TextInput::make('reference')->label(__('catalog.fields.reference'))->required()->maxLength(100),
                        TextInput::make('brand')->label(__('catalog.fields.brand'))->maxLength(100),
                    ])->columns(2)->defaultItems(0)->columnSpanFull(),
                Repeater::make('thresholds')->label(__('catalog.fields.thresholds'))->relationship()
                    ->schema([
                        Select::make('site_id')->label(__('catalog.fields.site'))->relationship('site', 'name')->required()->distinct(),
                        TextInput::make('min_quantity')->label(__('catalog.fields.min_quantity'))->integer()->minValue(0)->required(),
                    ])->columns(2)->defaultItems(0)->columnSpanFull(),
            ]),
        ]);
    }

    /** @param  array<int, Component>  $fields */
    private static function specSection(string $relation, CategoryType $type, string $title, array $fields): Section
    {
        return Section::make($title)
            ->relationship($relation, condition: fn (Get $get) => self::type($get) === $type)
            ->visible(fn (Get $get) => self::type($get) === $type)
            ->columns(3)
            ->schema($fields);
    }

    public static function type(Get $get): ?CategoryType
    {
        $categoryId = $get('category_id') ?? $get('../category_id');

        return $categoryId ? Category::query()->whereKey($categoryId)->value('type') : null;
    }

    /** @return array<int, string> */
    public static function categoryOptions(): array
    {
        return Category::query()->with('parent')
            ->whereNotIn('type', [CategoryType::Service->value, CategoryType::UsedTyre->value, CategoryType::Bundle->value])
            ->where('is_active', true)
            ->orderBy('parent_id')->orderBy('sort_order')
            ->get()
            ->mapWithKeys(fn (Category $c) => [$c->id => $c->fullName()])
            ->all();
    }
}
