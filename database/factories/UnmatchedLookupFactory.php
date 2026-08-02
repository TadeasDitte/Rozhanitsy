<?php

namespace Database\Factories;

use App\Models\UnmatchedLookup;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/**
 * @extends Factory<UnmatchedLookup>
 */
class UnmatchedLookupFactory extends Factory
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
            'hit_count' => fake()->numberBetween(1, 50),
            'first_seen_at' => now()->subDays(7),
            'last_seen_at' => now(),
        ];
    }
}
