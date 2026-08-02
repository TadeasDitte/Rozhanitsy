<?php

namespace Database\Factories;

use App\Models\Product;
use App\Models\Vendor;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/**
 * @extends Factory<Product>
 */
class ProductFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        $slug = fake()->unique()->slug(2);

        return [
            'vendor_id' => Vendor::factory(),
            'name' => Str::headline($slug),
            'slug' => $slug,
            'type' => fake()->randomElement(['core', 'plugin', 'theme', 'extension', 'package', 'library']),
        ];
    }

    public function plugin(): static
    {
        return $this->state(fn (array $attributes): array => ['type' => 'plugin']);
    }
}
