<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class PurchaseOrderLine extends Model
{
    public $timestamps = false;

    protected $guarded = ['id'];

    protected function casts(): array
    {
        return ['quantity_ordered' => 'integer', 'quantity_received' => 'integer', 'unit_cost_cents' => 'integer'];
    }

    public function product(): BelongsTo
    {
        return $this->belongsTo(Product::class);
    }

    public function remaining(): int
    {
        return max(0, $this->quantity_ordered - $this->quantity_received);
    }
}
