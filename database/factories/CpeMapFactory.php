<?php

namespace Database\Factories;

use App\Models\CpeMap;
use App\Models\Product;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/**
 * @extends Factory<CpeMap>
 */
class CpeMapFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'cpe_vendor' => Str::slug(fake()->unique()->company()),
            'cpe_product' => fake()->unique()->slug(2),
            'product_id' => Product::factory(),
            'match_type' => 'exact',
        ];
    }

    public function fuzzy(): static
    {
        return $this->state(fn (array $attributes): array => ['match_type' => 'fuzzy']);
    }

    public function forPair(string $cpeVendor, string $cpeProduct, Product $product): static
    {
        return $this->state(fn (array $attributes): array => [
            'cpe_vendor' => $cpeVendor,
            'cpe_product' => $cpeProduct,
            'product_id' => $product->id,
        ]);
    }
}
