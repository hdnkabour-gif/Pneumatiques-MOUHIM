<?php

namespace App\Models;

use App\Enums\CompatibilityStatus;
use App\Models\Concerns\IsFitment;
use App\Support\TyreSize;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;

/** Reference tyre size for a vehicle (§7.3). */
class TyreFitment extends Model
{
    use IsFitment;

    protected $fillable = [
        'external_id', 'model_id', 'generation_id', 'engine_id', 'source_id', 'status', 'source_checked_on', 'notes',
        'trim', 'position', 'width', 'aspect_ratio', 'rim_diameter', 'load_index', 'speed_index', 'is_xl', 'is_run_flat', 'is_reinforced',
    ];

    protected function casts(): array
    {
        return [
            'status' => CompatibilityStatus::class,
            'verified_at' => 'datetime',
            'source_checked_on' => 'date',
            'is_xl' => 'boolean',
            'is_run_flat' => 'boolean',
            'is_reinforced' => 'boolean',
            'width' => 'integer',
            'aspect_ratio' => 'integer',
        ];
    }

    public function sizeLabel(): string
    {
        return TyreSize::format($this->width, $this->aspect_ratio, (string) $this->rim_diameter, $this->load_index, $this->speed_index)
            .($this->is_reinforced ? ' C' : '').($this->is_xl ? ' XL' : '');
    }

    /** Tyre products of exactly this size (indices are shown, not filtered: the seller compares them). */
    public function matchingProducts(): Builder
    {
        return Product::query()->whereHas('tyreSpec', fn (Builder $q) => $q
            ->where('width', $this->width)
            ->where('rim_diameter', $this->rim_diameter)
            ->when($this->aspect_ratio, fn ($w) => $w->where('aspect_ratio', $this->aspect_ratio), fn ($w) => $w->whereNull('aspect_ratio')));
    }
}
