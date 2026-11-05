<?php

namespace App\Models;

use App\Enums\InventoryCountStatus;
use Illuminate\Database\Eloquent\Attributes\Scope;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class InventoryCount extends Model
{
    protected $guarded = ['id'];

    protected function casts(): array
    {
        return ['status' => InventoryCountStatus::class, 'submitted_at' => 'datetime', 'approved_at' => 'datetime'];
    }

    public function site(): BelongsTo
    {
        return $this->belongsTo(Site::class);
    }

    public function category(): BelongsTo
    {
        return $this->belongsTo(Category::class);
    }

    public function lines(): HasMany
    {
        return $this->hasMany(InventoryCountLine::class);
    }

    #[Scope]
    protected function visibleTo(Builder $query, User $user): void
    {
        if (! $user->canSeeAllSites()) {
            $query->whereIn('site_id', $user->siteIds());
        }
    }
}
