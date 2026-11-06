<?php

namespace App\Models;

use App\Support\Normalize;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class CustomerVehicle extends Model
{
    use HasFactory;

    protected $fillable = [
        'customer_id', 'plate', 'vehicle_model_id', 'vehicle_engine_id', 'description', 'year', 'mileage',
        'tyre_size_front', 'tyre_size_rear', 'next_oil_change_km', 'next_oil_change_on', 'notes',
    ];

    protected function casts(): array
    {
        return ['mileage' => 'integer', 'next_oil_change_km' => 'integer', 'next_oil_change_on' => 'date'];
    }

    protected static function booted(): void
    {
        static::saving(fn (CustomerVehicle $v) => $v->plate_normalized = Normalize::plate($v->plate) ?? '');
    }

    public function customer(): BelongsTo
    {
        return $this->belongsTo(Customer::class);
    }

    public function vehicleModel(): BelongsTo
    {
        return $this->belongsTo(VehicleModel::class);
    }

    public function engine(): BelongsTo
    {
        return $this->belongsTo(VehicleEngine::class, 'vehicle_engine_id');
    }

    public function sales(): HasMany
    {
        return $this->hasMany(Sale::class);
    }

    public function label(): string
    {
        $vehicle = $this->vehicleModel ? $this->vehicleModel->fullName() : $this->description;

        return trim(Normalize::plateDisplay($this->plate).($vehicle ? ' – '.$vehicle : ''));
    }
}
