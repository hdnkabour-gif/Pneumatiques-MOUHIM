<?php

namespace App\Models;

use App\Enums\OilDrumStatus;
use Illuminate\Database\Eloquent\Attributes\Scope;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/** One oil drum (fût). remaining_ml is maintained only by OilDrumService. */
class OilDrum extends Model
{
    protected $guarded = ['id', 'remaining_ml', 'cost_per_litre_cents', 'status'];

    protected function casts(): array
    {
        return [
            'status' => OilDrumStatus::class, 'capacity_ml' => 'integer', 'received_ml' => 'integer', 'remaining_ml' => 'integer',
            'purchase_cost_cents' => 'integer', 'extra_cost_cents' => 'integer', 'cost_per_litre_cents' => 'integer',
            'received_at' => 'date', 'opened_at' => 'datetime', 'emptied_at' => 'datetime',
        ];
    }

    public function product(): BelongsTo
    {
        return $this->belongsTo(Product::class);
    }

    public function site(): BelongsTo
    {
        return $this->belongsTo(Site::class);
    }

    public function movements(): HasMany
    {
        return $this->hasMany(OilDrumMovement::class)->orderBy('id');
    }

    #[Scope]
    protected function visibleTo(Builder $query, User $user): void
    {
        if (! $user->canSeeAllSites()) {
            $query->whereIn('site_id', $user->siteIds());
        }
    }

    /** Drums a seller can pour from at a site. */
    #[Scope]
    protected function usable(Builder $query): void
    {
        $query->whereIn('status', [OilDrumStatus::Closed->value, OilDrumStatus::Open->value])->where('remaining_ml', '>', 0);
    }

    public function remainingLitres(): string
    {
        return self::litres($this->remaining_ml);
    }

    public static function litres(int $ml): string
    {
        return rtrim(rtrim(number_format($ml / 1000, 3, ',', ' '), '0'), ',').' L';
    }
}
