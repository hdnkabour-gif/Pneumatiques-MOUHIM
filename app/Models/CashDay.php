<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** One site's cash for one day; closed = the day is locked (D-29). */
class CashDay extends Model
{
    protected $guarded = ['id'];

    protected function casts(): array
    {
        return ['business_date' => 'date', 'closed_at' => 'datetime', 'opening_float_cents' => 'integer',
            'expected_cents' => 'integer', 'counted_cents' => 'integer', 'difference_cents' => 'integer'];
    }

    public function isClosed(): bool
    {
        return $this->closed_at !== null;
    }

    public function site(): BelongsTo
    {
        return $this->belongsTo(Site::class);
    }

    public function closer(): BelongsTo
    {
        return $this->belongsTo(User::class, 'closed_by');
    }
}
