<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Scope;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Equipment extends Model
{
    public const CATEGORIES = ['tyre_changer', 'balancer', 'alignment', 'compressor', 'nitrogen', 'lift', 'jack', 'impact_wrench', 'oil_equipment', 'headlight_tester', 'other'];

    protected $table = 'equipment';

    protected $guarded = ['id', 'code'];

    protected function casts(): array
    {
        return ['purchased_at' => 'date', 'warranty_until' => 'date', 'next_maintenance_on' => 'date', 'purchase_cost_cents' => 'integer'];
    }

    public function site(): BelongsTo
    {
        return $this->belongsTo(Site::class);
    }

    public function events(): HasMany
    {
        return $this->hasMany(EquipmentEvent::class)->latest('occurred_on')->latest('id');
    }

    #[Scope]
    protected function visibleTo(Builder $query, User $user): void
    {
        if (! $user->canSeeAllSites()) {
            $query->whereIn('site_id', $user->siteIds());
        }
    }

    public function totalCostCents(): int
    {
        return (int) $this->events()->sum('cost_cents');
    }
}
