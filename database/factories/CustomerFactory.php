<?php

namespace Database\Factories;

use App\Enums\CustomerType;
use App\Enums\PriceLevel;
use App\Models\Customer;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Customer>
 */
class CustomerFactory extends Factory
{
    protected $model = Customer::class;

    public function definition(): array
    {
        return [
            'type' => CustomerType::Individual,
            'name' => fake()->name(),
            'phone' => '06'.fake()->numerify('########'),
            'price_level' => PriceLevel::Retail,
        ];
    }

    public function company(int $creditLimitCents = 1000000): static
    {
        return $this->state(fn () => [
            'type' => CustomerType::Company,
            'name' => fake()->company(),
            'ice' => fake()->numerify('###############'),
            'price_level' => PriceLevel::Pro,
            'credit_limit_cents' => $creditLimitCents,
        ]);
    }
}
