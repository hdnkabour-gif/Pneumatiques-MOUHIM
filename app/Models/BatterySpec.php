<?php

namespace App\Models;

use App\Enums\BatteryTechnology;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class BatterySpec extends Model
{
    public $timestamps = false;

    protected $primaryKey = 'product_id';

    public $incrementing = false;

    protected $fillable = ['product_id', 'voltage', 'capacity_ah', 'cca', 'dimensions', 'polarity', 'technology', 'warranty_months'];

    protected function casts(): array
    {
        return ['technology' => BatteryTechnology::class];
    }

    public function product(): BelongsTo
    {
        return $this->belongsTo(Product::class);
    }
}
