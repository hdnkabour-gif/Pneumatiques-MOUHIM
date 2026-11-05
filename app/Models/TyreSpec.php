<?php

namespace App\Models;

use App\Enums\TyreSeason;
use App\Enums\TyreUsage;
use App\Support\TyreSize;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class TyreSpec extends Model
{
    public $timestamps = false;

    protected $primaryKey = 'product_id';

    public $incrementing = false;

    protected $fillable = ['product_id', 'width', 'aspect_ratio', 'rim_diameter', 'load_index', 'speed_index', 'season', 'is_xl', 'is_run_flat', 'is_reinforced', 'usage', 'pattern', 'oe_marking', 'origin_country', 'warranty_months'];

    protected function casts(): array
    {
        return ['season' => TyreSeason::class, 'usage' => TyreUsage::class, 'is_xl' => 'boolean', 'is_run_flat' => 'boolean', 'is_reinforced' => 'boolean', 'width' => 'integer', 'aspect_ratio' => 'integer'];
    }

    public function product(): BelongsTo
    {
        return $this->belongsTo(Product::class);
    }

    /** "205/55 R16 91V" */
    public function sizeLabel(): string
    {
        return TyreSize::format($this->width, $this->aspect_ratio, (string) $this->rim_diameter, $this->load_index, $this->speed_index);
    }
}
