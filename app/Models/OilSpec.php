<?php

namespace App\Models;

use App\Enums\OilType;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class OilSpec extends Model
{
    public $timestamps = false;

    protected $primaryKey = 'product_id';

    public $incrementing = false;

    protected $fillable = ['product_id', 'viscosity', 'oil_type', 'volume_ml', 'standards', 'oem_approvals'];

    protected function casts(): array
    {
        return ['oil_type' => OilType::class, 'volume_ml' => 'integer'];
    }

    public function product(): BelongsTo
    {
        return $this->belongsTo(Product::class);
    }
}
