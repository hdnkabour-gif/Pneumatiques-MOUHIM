<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** Debt carried over from the paper books (D-23), paid like an invoice. */
class CustomerOpeningBalance extends Model
{
    protected $guarded = ['id'];

    protected function casts(): array
    {
        return ['amount_cents' => 'integer', 'remaining_cents' => 'integer', 'as_of' => 'date'];
    }

    public function customer(): BelongsTo
    {
        return $this->belongsTo(Customer::class);
    }
}
