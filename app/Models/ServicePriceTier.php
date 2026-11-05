<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ServicePriceTier extends Model
{
    public $timestamps = false;

    protected $fillable = ['product_id', 'min_rim_diameter', 'max_rim_diameter', 'price_cents'];

    protected function casts(): array
    {
        return ['price_cents' => 'integer'];
    }

    public function product(): BelongsTo
    {
        return $this->belongsTo(Product::class);
    }
}
