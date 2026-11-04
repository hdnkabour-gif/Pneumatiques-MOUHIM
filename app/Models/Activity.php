<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Relations\BelongsTo;
use LogicException;
use Spatie\Activitylog\Models\Activity as BaseActivity;

/**
 * Append-only activity log entry. Updates and deletes are refused here and,
 * independently, by a PostgreSQL trigger.
 */
class Activity extends BaseActivity
{
    protected static function booted(): void
    {
        static::updating(fn () => throw new LogicException('Activity log entries cannot be modified.'));
        static::deleting(fn () => throw new LogicException('Activity log entries cannot be deleted.'));
    }

    public function site(): BelongsTo
    {
        return $this->belongsTo(Site::class);
    }
}
