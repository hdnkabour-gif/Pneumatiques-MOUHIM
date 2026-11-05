<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class UsedTyreInspection extends Model
{
    public const UPDATED_AT = null;

    protected $guarded = ['id'];

    protected function casts(): array
    {
        return ['checks' => 'array', 'passed' => 'boolean', 'tread_depth_tenths' => 'integer'];
    }

    public function usedTyre(): BelongsTo
    {
        return $this->belongsTo(UsedTyre::class);
    }

    public function inspector(): BelongsTo
    {
        return $this->belongsTo(User::class, 'inspector_id');
    }
}
