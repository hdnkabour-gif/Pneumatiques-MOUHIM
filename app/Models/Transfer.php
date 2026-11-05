<?php

namespace App\Models;

use App\Enums\TransferStatus;
use Illuminate\Database\Eloquent\Attributes\Scope;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/** Inter-site transfer (D-11). Created and changed only by the transfer actions. */
class Transfer extends Model
{
    protected $guarded = ['id'];

    protected function casts(): array
    {
        return [
            'status' => TransferStatus::class,
            'sent_at' => 'datetime',
            'received_at' => 'datetime',
            'cancelled_at' => 'datetime',
        ];
    }

    public function fromSite(): BelongsTo
    {
        return $this->belongsTo(Site::class, 'from_site_id');
    }

    public function toSite(): BelongsTo
    {
        return $this->belongsTo(Site::class, 'to_site_id');
    }

    public function lines(): HasMany
    {
        return $this->hasMany(TransferLine::class);
    }

    public function sender(): BelongsTo
    {
        return $this->belongsTo(User::class, 'sent_by');
    }

    public function receiver(): BelongsTo
    {
        return $this->belongsTo(User::class, 'received_by');
    }

    public function replenishmentRequest(): BelongsTo
    {
        return $this->belongsTo(ReplenishmentRequest::class);
    }

    /** Transfers touching one of the user's sites. */
    #[Scope]
    protected function visibleTo(Builder $query, User $user): void
    {
        if ($user->canSeeAllSites()) {
            return;
        }

        $query->where(fn (Builder $q) => $q->whereIn('from_site_id', $user->siteIds())->orWhereIn('to_site_id', $user->siteIds()));
    }
}
