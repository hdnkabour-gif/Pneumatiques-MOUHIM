<?php

namespace Database\Factories;

use App\Enums\SiteType;
use App\Models\Site;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Site>
 */
class SiteFactory extends Factory
{
    protected $model = Site::class;

    public function definition(): array
    {
        return [
            'code' => strtoupper(fake()->unique()->bothify('S##')),
            'name' => 'Site '.fake()->unique()->city(),
            'type' => SiteType::Store,
            'has_workshop' => true,
            'sales_enabled' => true,
            'is_active' => true,
        ];
    }

    public function depot(): static
    {
        return $this->state(fn () => ['type' => SiteType::Depot, 'has_workshop' => false, 'sales_enabled' => false]);
    }
}
