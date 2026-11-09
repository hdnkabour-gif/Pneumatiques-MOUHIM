<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class PurchaseReceipt extends Model
{
    protected $guarded = ['id'];

    protected function casts(): array
    {
        return ['received_at' => 'datetime', 'total_ht_cents' => 'integer', 'total_ttc_cents' => 'integer', 'vat_rate_bp' => 'integer'];
    }

    public function supplier(): BelongsTo
    {
        return $this->belongsTo(Supplier::class);
    }

    public function site(): BelongsTo
    {
        return $this->belongsTo(Site::class);
    }

    public function lines(): HasMany
    {
        return $this->hasMany(PurchaseReceiptLine::class);
    }
}
