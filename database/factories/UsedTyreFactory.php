<?php

namespace Database\Factories;

use App\Models\UsedTyre;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * Attribute data only: used tyres are created through UsedTyreActions::register().
 *
 * @extends Factory<UsedTyre>
 */
class UsedTyreFactory extends Factory
{
    protected $model = UsedTyre::class;

    public function definition(): array
    {
        return [
            'width' => 205,
            'aspect_ratio' => 55,
            'rim_diameter' => '16.0',
            'pattern' => 'Primacy 4',
            'tread_depth_tenths' => 50,
            'dot_week' => 12,
            'dot_year' => 2023,
            'cost_cents' => 15000,
            'price_cents' => 30000,
        ];
    }
}
