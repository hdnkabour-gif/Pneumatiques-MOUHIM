<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Supplier extends Model
{
    protected $guarded = ['id'];

    protected function casts(): array
    {
        return ['is_active' => 'boolean', 'opening_balance_cents' => 'integer'];
    }

    public function orders(): HasMany
    {
        return $this->hasMany(PurchaseOrder::class);
    }

    public function receipts(): HasMany
    {
        return $this->hasMany(PurchaseReceipt::class)->latest('received_at');
    }

    public function payments(): HasMany
    {
        return $this->hasMany(SupplierPayment::class)->latest('paid_at');
    }

    /** What we owe: opening balance + receipts (TTC) − payments. */
    public function balanceCents(): int
    {
        return $this->opening_balance_cents + (int) $this->receipts()->sum('total_ttc_cents') - (int) $this->payments()->sum('amount_cents');
    }
}
