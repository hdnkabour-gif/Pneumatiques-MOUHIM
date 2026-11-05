<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ProductReference extends Model
{
    protected $fillable = ['product_id', 'reference', 'brand'];

    public function product(): BelongsTo
    {
        return $this->belongsTo(Product::class);
    }
}
