<?php

namespace App\Models;

use App\Enums\CategoryType;
use App\Enums\ProductKind;
use App\Enums\Unit;
use App\Services\SequenceService;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Spatie\Activitylog\Models\Concerns\LogsActivity;
use Spatie\Activitylog\Support\LogOptions;

/**
 * A sellable item: goods (stocked), service, or bundle (§5).
 * Amounts are integer cents. average_cost_cents is maintained only by
 * App\Services\Stock\StockService (weighted average, D-09).
 */
class Product extends Model
{
    use HasFactory, LogsActivity;

    protected $fillable = [
        'sku', 'kind', 'category_id', 'brand_id', 'name', 'name_ar', 'manufacturer_ref', 'unit',
        'price_retail_cents', 'price_pro_cents', 'price_reseller_cents', 'reference_cost_cents',
        'duration_minutes', 'description', 'is_active',
    ];

    protected function casts(): array
    {
        return [
            'kind' => ProductKind::class,
            'unit' => Unit::class,
            'price_retail_cents' => 'integer',
            'price_pro_cents' => 'integer',
            'price_reseller_cents' => 'integer',
            'reference_cost_cents' => 'integer',
            'average_cost_cents' => 'integer',
            'is_active' => 'boolean',
        ];
    }

    /** SKU generated from the category prefix when not given (D-41). */
    protected static function booted(): void
    {
        static::creating(function (Product $product) {
            if (blank($product->sku)) {
                $prefix = Category::query()->whereKey($product->category_id)->value('sku_prefix') ?: 'PR';
                $product->sku = app(SequenceService::class)->nextSku($prefix);
            }
        });
    }

    public function category(): BelongsTo
    {
        return $this->belongsTo(Category::class);
    }

    public function brand(): BelongsTo
    {
        return $this->belongsTo(Brand::class);
    }

    public function references(): HasMany
    {
        return $this->hasMany(ProductReference::class);
    }

    public function tyreSpec(): HasOne
    {
        return $this->hasOne(TyreSpec::class);
    }

    public function oilSpec(): HasOne
    {
        return $this->hasOne(OilSpec::class);
    }

    public function batterySpec(): HasOne
    {
        return $this->hasOne(BatterySpec::class);
    }

    public function filterSpec(): HasOne
    {
        return $this->hasOne(FilterSpec::class);
    }

    public function lightingSpec(): HasOne
    {
        return $this->hasOne(LightingSpec::class);
    }

    public function thresholds(): HasMany
    {
        return $this->hasMany(ProductSiteThreshold::class);
    }

    public function stockLevels(): HasMany
    {
        return $this->hasMany(StockLevel::class);
    }

    public function movements(): HasMany
    {
        return $this->hasMany(StockMovement::class);
    }

    public function lots(): HasMany
    {
        return $this->hasMany(TyreLot::class);
    }

    public function priceTiers(): HasMany
    {
        return $this->hasMany(ServicePriceTier::class)->orderBy('min_rim_diameter');
    }

    public function consumables(): HasMany
    {
        return $this->hasMany(ServiceConsumable::class, 'service_id');
    }

    public function bundleItems(): HasMany
    {
        return $this->hasMany(BundleItem::class, 'bundle_id')->orderBy('sort_order');
    }

    /** Sites where a service is offered (empty = all sites). */
    public function sites(): BelongsToMany
    {
        return $this->belongsToMany(Site::class);
    }

    /** Counted in stock_levels (pieces, cans…). Bulk oil lives in drums instead. */
    public function isStocked(): bool
    {
        return $this->kind === ProductKind::Goods && ! $this->isBulk();
    }

    /** Oil sold by the litre from drums (D-06 revised): price per litre, stock per drum. */
    public function isBulk(): bool
    {
        return $this->unit === Unit::Litre;
    }

    public function drums(): HasMany
    {
        return $this->hasMany(OilDrum::class);
    }

    public function isNewTyre(): bool
    {
        return $this->category?->type === CategoryType::NewTyre;
    }

    public function displayName(): string
    {
        return app()->getLocale() === 'ar' && filled($this->name_ar) ? $this->name_ar : $this->name;
    }

    /**
     * Price for a price level (D-17); professional / reseller fall back to retail.
     */
    public function priceFor(string $level = 'retail'): int
    {
        return match ($level) {
            'pro' => $this->price_pro_cents ?? $this->price_retail_cents,
            'reseller' => $this->price_reseller_cents ?? $this->price_pro_cents ?? $this->price_retail_cents,
            default => $this->price_retail_cents,
        };
    }

    /**
     * Suggested selling price = cost + category margin (D-18, D-40).
     * Null when the cost or the margin is unknown: never guessed.
     */
    public function suggestedPriceCents(): ?int
    {
        $cost = $this->average_cost_cents ?? $this->reference_cost_cents;
        $margin = $this->category?->default_margin_bp;

        if ($cost === null || $margin === null) {
            return null;
        }

        return intdiv($cost * (10000 + $margin) + 5000, 10000);
    }

    public function getActivitylogOptions(): LogOptions
    {
        return LogOptions::defaults()
            ->logOnly([...$this->fillable, 'average_cost_cents'])
            ->logOnlyDirty()
            ->dontLogEmptyChanges();
    }
}
