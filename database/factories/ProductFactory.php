<?php

namespace Database\Factories;

use App\Enums\ProductKind;
use App\Enums\Unit;
use App\Models\Category;
use App\Models\Product;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Product>
 */
class ProductFactory extends Factory
{
    protected $model = Product::class;

    public function definition(): array
    {
        return [
            'kind' => ProductKind::Goods,
            'category_id' => fn () => $this->category('Accessoires et produits d\'entretien')->id,
            'name' => 'Produit '.fake()->unique()->bothify('??-###'),
            'unit' => Unit::Piece,
            'price_retail_cents' => 10000,
            'is_active' => true,
        ];
    }

    /** New tyre with its size (e.g. 205/55 R16). */
    public function tyre(int $width = 205, ?int $ratio = 55, string $diameter = '16'): static
    {
        return $this->state(fn () => [
            'category_id' => $this->category('Tourisme')->id,
            'name' => "Pneu {$width}/{$ratio} R{$diameter}",
            'price_retail_cents' => 80000,
        ])->afterCreating(fn (Product $p) => $p->tyreSpec()->create([
            'width' => $width, 'aspect_ratio' => $ratio, 'rim_diameter' => $diameter, 'load_index' => '91', 'speed_index' => 'V',
        ]));
    }

    public function service(): static
    {
        return $this->state(fn () => [
            'kind' => ProductKind::Service,
            'category_id' => $this->category('Pneumatique')->id,
            'name' => 'Service '.fake()->unique()->word(),
            'price_retail_cents' => 5000,
        ]);
    }

    public function bundle(): static
    {
        return $this->state(fn () => [
            'kind' => ProductKind::Bundle,
            'category_id' => Category::query()->where('name', 'Forfaits')->firstOrFail()->id,
            'name' => 'Forfait '.fake()->unique()->word(),
        ]);
    }

    private function category(string $name): Category
    {
        return Category::query()->where('name', $name)->firstOrFail();
    }
}
