<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * Key/value application setting. Read and written only through
 * App\Services\SettingsService.
 */
class Setting extends Model
{
    protected $fillable = ['key', 'value', 'updated_by'];

    protected function casts(): array
    {
        return ['value' => 'json'];
    }
}
