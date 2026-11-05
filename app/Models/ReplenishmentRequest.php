<?php

namespace App\Models;

use App\Enums\ReplenishmentStatus;
use Illuminate\Database\Eloquent\Attributes\Scope;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;

class ReplenishmentRequest extends Model
{
    protected $guarded = ['id'];

    protected function casts(): array
    {
        return ['status' => ReplenishmentStatus::class];
    }

    public function site(): BelongsTo
    {
        return $this->belongsTo(Site::class);
    }

    public function sourceSite(): BelongsTo
    {
        return $this->belongsTo(Site::class, 'source_site_id');
    }

    public function requester(): BelongsTo
    {
        return $this->belongsTo(User::class, 'requested_by');
    }

    public function lines(): HasMany
    {
        return $this->hasMany(ReplenishmentRequestLine::class);
    }

    public function transfer(): HasOne
    {
        return $this->hasOne(Transfer::class);
    }

    #[Scope]
    protected function visibleTo(Builder $query, User $user): void
    {
        if ($user->canSeeAllSites()) {
            return;
        }

        $query->where(fn (Builder $q) => $q->whereIn('site_id', $user->siteIds())->orWhereIn('source_site_id', $user->siteIds()));
    }
}
