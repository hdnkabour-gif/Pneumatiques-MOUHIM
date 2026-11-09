<?php

namespace App\Models;

use App\Enums\OilDrumMovementType;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\MorphTo;

/** Append-only ledger of a drum (database trigger). */
class OilDrumMovement extends Model
{
    public const UPDATED_AT = null;

    protected $guarded = ['id'];

    protected function casts(): array
    {
        return ['type' => OilDrumMovementType::class, 'quantity_ml' => 'integer', 'cost_per_litre_cents' => 'integer', 'created_at' => 'datetime'];
    }

    public function drum(): BelongsTo
    {
        return $this->belongsTo(OilDrum::class, 'oil_drum_id');
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function document(): MorphTo
    {
        return $this->morphTo();
    }
}
