<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** Which source tyre lots (DOT) were sent on a transfer line. */
class TransferLineLot extends Model
{
    public $timestamps = false;

    protected $guarded = ['id'];

    protected function casts(): array
    {
        return ['quantity' => 'integer'];
    }

    public function sourceLot(): BelongsTo
    {
        return $this->belongsTo(TyreLot::class, 'source_lot_id');
    }
}
