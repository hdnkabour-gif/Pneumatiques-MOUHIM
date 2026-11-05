<?php

namespace App\Models;

use App\Enums\CompatibilityStatus;
use App\Models\Concerns\IsFitment;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;

/** Engine oil requirement for a vehicle engine (§7.3). */
class OilFitment extends Model
{
    use IsFitment;

    protected $fillable = [
        'external_id', 'model_id', 'generation_id', 'engine_id', 'source_id', 'status', 'source_checked_on', 'notes',
        'engine_label', 'viscosity', 'acea', 'api', 'oem_approval', 'capacity_ml', 'oil_filter_ref',
    ];

    protected function casts(): array
    {
        return [
            'status' => CompatibilityStatus::class,
            'verified_at' => 'datetime',
            'source_checked_on' => 'date',
            'capacity_ml' => 'integer',
        ];
    }

    /** Oils of this viscosity; the norm / OEM approval must still be checked on the product sheet. */
    public function matchingProducts(): Builder
    {
        $viscosity = strtoupper(str_replace(['-', ' '], '', (string) $this->viscosity));

        return Product::query()->whereHas('oilSpec', fn (Builder $q) => $q->where('viscosity', $viscosity));
    }

    public function capacityLitres(): ?string
    {
        return $this->capacity_ml === null ? null : rtrim(rtrim(number_format($this->capacity_ml / 1000, 2, ',', ''), '0'), ',');
    }
}
