<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\MorphTo;

/** Stock held for an accepted quote or draft sale (D-13). Used from phase 3. */
class StockReservation extends Model
{
    protected $fillable = ['product_id', 'site_id', 'quantity', 'reservable_type', 'reservable_id', 'user_id', 'expires_at'];

    protected function casts(): array
    {
        return ['quantity' => 'integer', 'expires_at' => 'datetime'];
    }

    public function product(): BelongsTo
    {
        return $this->belongsTo(Product::class);
    }

    public function reservable(): MorphTo
    {
        return $this->morphTo();
    }
}
