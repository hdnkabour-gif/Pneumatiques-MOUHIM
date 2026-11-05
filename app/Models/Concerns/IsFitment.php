<?php

namespace App\Models\Concerns;

use App\Enums\CompatibilityStatus;
use App\Models\CompatibilitySource;
use App\Models\User;
use App\Models\VehicleEngine;
use App\Models\VehicleGeneration;
use App\Models\VehicleModel;
use Illuminate\Database\Eloquent\Attributes\Scope;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Spatie\Activitylog\Models\Concerns\LogsActivity;
use Spatie\Activitylog\Support\LogOptions;

/** Shared by tyre and oil fitments: vehicle, source, status, verification (§7.3). */
trait IsFitment
{
    use LogsActivity;

    public function model(): BelongsTo
    {
        return $this->belongsTo(VehicleModel::class, 'model_id');
    }

    public function generation(): BelongsTo
    {
        return $this->belongsTo(VehicleGeneration::class, 'generation_id');
    }

    public function engine(): BelongsTo
    {
        return $this->belongsTo(VehicleEngine::class, 'engine_id');
    }

    public function source(): BelongsTo
    {
        return $this->belongsTo(CompatibilitySource::class, 'source_id');
    }

    public function verifier(): BelongsTo
    {
        return $this->belongsTo(User::class, 'verified_by');
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    /** The only rows ever presented as compatible. */
    #[Scope]
    protected function verified(Builder $query): void
    {
        $query->where('status', CompatibilityStatus::Verified->value);
    }

    public function getActivitylogOptions(): LogOptions
    {
        return LogOptions::defaults()->logAll()->logExcept(['updated_at', 'created_at'])->logOnlyDirty()->dontLogEmptyChanges();
    }
}
