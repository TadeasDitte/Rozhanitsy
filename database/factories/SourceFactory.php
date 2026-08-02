<?php

namespace Database\Factories;

use App\Models\Source;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Source>
 */
class SourceFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'slug' => fake()->unique()->slug(2),
            'name' => fake()->unique()->company(),
            'url' => fake()->url(),
        ];
    }

    public function nvd(): static
    {
        return $this->state(fn (array $attributes): array => [
            'slug' => 'nvd',
            'name' => 'NIST NVD',
            'url' => 'https://services.nvd.nist.gov/rest/json/cves/2.0',
        ]);
    }
}
