<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class VehicleModel extends Model
{
    protected $fillable = ['make_id', 'name', 'body_type', 'notes', 'is_active'];

    protected function casts(): array
    {
        return ['is_active' => 'boolean'];
    }

    public function make(): BelongsTo
    {
        return $this->belongsTo(VehicleMake::class, 'make_id');
    }

    public function generations(): HasMany
    {
        return $this->hasMany(VehicleGeneration::class, 'model_id');
    }

    public function engines(): HasMany
    {
        return $this->hasMany(VehicleEngine::class, 'model_id');
    }

    public function tyreFitments(): HasMany
    {
        return $this->hasMany(TyreFitment::class, 'model_id');
    }

    public function oilFitments(): HasMany
    {
        return $this->hasMany(OilFitment::class, 'model_id');
    }

    public function fullName(): string
    {
        return $this->make->name.' '.$this->name;
    }
}
