<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class OilCatalogueReference extends Model
{
    protected $fillable = ['record_type', 'brand', 'model', 'dimension', 'specifications', 'verification_status', 'source_sheet', 'source_row', 'unique_key'];

    protected function casts(): array
    {
        return ['specifications' => 'array', 'source_row' => 'integer'];
    }
}
