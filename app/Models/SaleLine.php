<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class SaleLine extends Model
{
    public $timestamps = false;

    protected $guarded = ['id'];

    protected function casts(): array
    {
        return [
            'quantity' => 'integer', 'list_price_ttc_cents' => 'integer', 'unit_price_ttc_cents' => 'integer',
            'discount_bp' => 'integer', 'vat_rate_bp' => 'integer', 'total_ht_cents' => 'integer',
            'total_vat_cents' => 'integer', 'total_ttc_cents' => 'integer', 'unit_cost_cents' => 'integer',
        ];
    }

    public function sale(): BelongsTo
    {
        return $this->belongsTo(Sale::class);
    }

    public function product(): BelongsTo
    {
        return $this->belongsTo(Product::class);
    }
}
