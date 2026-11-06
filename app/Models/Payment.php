<?php

namespace App\Models;

use App\Enums\PaymentMethod;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;

/** Money received (D-50). Phase 4 adds cheque follow-up, customer accounts and the cash register. */
class Payment extends Model
{
    protected $guarded = ['id'];

    protected function casts(): array
    {
        return ['method' => PaymentMethod::class, 'amount_cents' => 'integer', 'due_date' => 'date', 'received_at' => 'datetime'];
    }

    public function customer(): BelongsTo
    {
        return $this->belongsTo(Customer::class);
    }

    public function sales(): BelongsToMany
    {
        return $this->belongsToMany(Sale::class, 'payment_allocations')->withPivot('amount_cents');
    }
}
