<?php

namespace App\Models;

use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** Batch of new tyres at a site, with DOT (§5.3). quantity is a cache of its movements. */
class TyreLot extends Model
{
    protected $fillable = ['product_id', 'site_id', 'dot_week', 'dot_year', 'unit_cost_cents', 'supplier', 'received_at', 'source_lot_id'];

    protected function casts(): array
    {
        return [
            'quantity' => 'integer',
            'dot_week' => 'integer',
            'dot_year' => 'integer',
            'unit_cost_cents' => 'integer',
            'received_at' => 'date',
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

    /** "2324" (week 23 of 2024), or null when unreadable. */
    public function dotLabel(): ?string
    {
        if ($this->dot_week === null || $this->dot_year === null) {
            return null;
        }

        return sprintf('%02d%02d', $this->dot_week, $this->dot_year % 100);
    }

    /** Age in whole months from the DOT manufacturing week. */
    public function ageInMonths(?CarbonImmutable $now = null): ?int
    {
        if ($this->dot_week === null || $this->dot_year === null) {
            return null;
        }

        $made = CarbonImmutable::now()->setISODate($this->dot_year, max(1, $this->dot_week))->startOfDay();

        return (int) $made->diffInMonths($now ?? CarbonImmutable::now());
    }
}
