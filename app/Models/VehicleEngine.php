<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Spatie\Activitylog\Models\Concerns\LogsActivity;
use Spatie\Activitylog\Support\LogOptions;

class VehicleEngine extends Model
{
    use LogsActivity;

    protected $fillable = [
        'external_id', 'model_id', 'generation_id', 'label', 'designation', 'engine_code', 'displacement_cc', 'cylinders',
        'fuel', 'power_hp', 'power_kw', 'torque_nm', 'gearbox', 'gears', 'year_from', 'year_to', 'notes',
    ];

    public function model(): BelongsTo
    {
        return $this->belongsTo(VehicleModel::class, 'model_id');
    }

    public function generation(): BelongsTo
    {
        return $this->belongsTo(VehicleGeneration::class, 'generation_id');
    }

    public function getActivitylogOptions(): LogOptions
    {
        return LogOptions::defaults()->logFillable()->logOnlyDirty()->dontLogEmptyChanges();
    }
}
