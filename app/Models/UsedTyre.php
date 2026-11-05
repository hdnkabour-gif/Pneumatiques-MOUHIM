<?php

namespace App\Models;

use App\Enums\UsedTyreOrigin;
use App\Enums\UsedTyreStatus;
use App\Support\TyreSize;
use Illuminate\Database\Eloquent\Attributes\Scope;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Database\Eloquent\Relations\MorphTo;
use Spatie\Activitylog\Models\Concerns\LogsActivity;
use Spatie\Activitylog\Support\LogOptions;

/**
 * One individually tracked used tyre (§5.4). Status and site are changed
 * only by the used-tyre actions, which also write the stock movement.
 */
class UsedTyre extends Model
{
    use HasFactory, LogsActivity;

    protected $fillable = [
        'location', 'brand_id', 'pattern', 'width', 'aspect_ratio', 'rim_diameter', 'load_index', 'speed_index',
        'dot_week', 'dot_year', 'tread_depth_tenths', 'cost_cents', 'price_cents', 'photos', 'notes',
    ];

    protected function casts(): array
    {
        return [
            'status' => UsedTyreStatus::class,
            'origin' => UsedTyreOrigin::class,
            'width' => 'integer',
            'aspect_ratio' => 'integer',
            'dot_week' => 'integer',
            'dot_year' => 'integer',
            'tread_depth_tenths' => 'integer',
            'cost_cents' => 'integer',
            'price_cents' => 'integer',
            'photos' => 'array',
        ];
    }

    public function site(): BelongsTo
    {
        return $this->belongsTo(Site::class);
    }

    public function brand(): BelongsTo
    {
        return $this->belongsTo(Brand::class);
    }

    /** The sale (trade-in) or other document the tyre came from. */
    public function originDocument(): MorphTo
    {
        return $this->morphTo();
    }

    public function inspections(): HasMany
    {
        return $this->hasMany(UsedTyreInspection::class)->latest('id');
    }

    public function latestInspection(): HasOne
    {
        return $this->hasOne(UsedTyreInspection::class)->latestOfMany();
    }

    /** Tyres that may be shown in sales searches (§21: unsafe tyres never appear). */
    #[Scope]
    protected function sellable(Builder $query): void
    {
        $query->where('status', UsedTyreStatus::InStock->value);
    }

    public function sizeLabel(): string
    {
        return TyreSize::format($this->width, $this->aspect_ratio, (string) $this->rim_diameter, $this->load_index, $this->speed_index);
    }

    public function treadDepthMm(): ?string
    {
        return $this->tread_depth_tenths === null ? null : number_format($this->tread_depth_tenths / 10, 1, '.', '');
    }

    public function getActivitylogOptions(): LogOptions
    {
        return LogOptions::defaults()->logOnly([...$this->fillable, 'status', 'site_id'])->logOnlyDirty()->dontLogEmptyChanges();
    }
}
