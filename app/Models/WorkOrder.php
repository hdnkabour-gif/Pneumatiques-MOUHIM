<?php

namespace App\Models;

use App\Enums\WorkOrderStatus;
use Illuminate\Database\Eloquent\Attributes\Scope;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Spatie\Activitylog\Models\Concerns\LogsActivity;
use Spatie\Activitylog\Support\LogOptions;

/** Workshop job (§14). Created with the sale; the seller assigns technicians and moves the status (D-53). */
class WorkOrder extends Model
{
    use LogsActivity;

    protected $guarded = ['id'];

    protected function casts(): array
    {
        return ['status' => WorkOrderStatus::class, 'started_at' => 'datetime', 'finished_at' => 'datetime', 'delivered_at' => 'datetime'];
    }

    public function site(): BelongsTo
    {
        return $this->belongsTo(Site::class);
    }

    public function sale(): BelongsTo
    {
        return $this->belongsTo(Sale::class);
    }

    public function customer(): BelongsTo
    {
        return $this->belongsTo(Customer::class);
    }

    public function vehicle(): BelongsTo
    {
        return $this->belongsTo(CustomerVehicle::class, 'customer_vehicle_id');
    }

    public function technicians(): BelongsToMany
    {
        return $this->belongsToMany(User::class);
    }

    #[Scope]
    protected function visibleTo(Builder $query, User $user): void
    {
        if (! $user->canSeeAllSites()) {
            $query->whereIn('site_id', $user->siteIds());
        }
    }

    public function getActivitylogOptions(): LogOptions
    {
        return LogOptions::defaults()->logOnly(['status', 'started_at', 'finished_at', 'delivered_at', 'notes'])->logOnlyDirty()->dontLogEmptyChanges();
    }
}
