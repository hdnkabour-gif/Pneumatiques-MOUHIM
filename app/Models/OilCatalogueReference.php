<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Spatie\Activitylog\Models\Concerns\LogsActivity;
use Spatie\Activitylog\Support\LogOptions;

class OilCatalogueReference extends Model
{
    use LogsActivity;

    protected $fillable = ['record_type', 'brand', 'model', 'dimension', 'specifications', 'verification_status', 'source_sheet', 'source_row', 'unique_key'];

    protected function casts(): array
    {
        return ['specifications' => 'array', 'source_row' => 'integer'];
    }

    public function getActivitylogOptions(): LogOptions
    {
        return LogOptions::defaults()->logFillable()->logOnlyDirty()->dontLogEmptyChanges();
    }
}
