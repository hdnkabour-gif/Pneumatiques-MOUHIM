<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** Cached quantity per product × site (D-08). Written only by StockService. */
class StockLevel extends Model
{
    protected $fillable = ['product_id', 'site_id'];

    protected function casts(): array
    {
        return ['quantity' => 'integer', 'reserved' => 'integer'];
    }

    public function product(): BelongsTo
    {
        return $this->belongsTo(Product::class);
    }

    public function site(): BelongsTo
    {
        return $this->belongsTo(Site::class);
    }

    public function available(): int
    {
        return max(0, $this->quantity - $this->reserved);
    }
}
