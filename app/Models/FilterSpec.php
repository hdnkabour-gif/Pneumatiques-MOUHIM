<?php

namespace App\Models;

use App\Enums\FilterType;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class FilterSpec extends Model
{
    public $timestamps = false;

    protected $primaryKey = 'product_id';

    public $incrementing = false;

    protected $fillable = ['product_id', 'filter_type'];

    protected function casts(): array
    {
        return ['filter_type' => FilterType::class];
    }

    public function product(): BelongsTo
    {
        return $this->belongsTo(Product::class);
    }
}
