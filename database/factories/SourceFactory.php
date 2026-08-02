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
            'driver' => null,
            'page_size' => null,
            'request_delay_ms' => null,
            'unauthenticated_request_delay_ms' => null,
        ];
    }

    public function nvd(): static
    {
        return $this->state(fn (array $attributes): array => [
            'slug' => 'nvd',
            'name' => 'NIST NVD',
            'url' => 'https://services.nvd.nist.gov/rest/json/cves/2.0',
            'driver' => 'nvd',
            'page_size' => 2000,

            'request_delay_ms' => 0,
            'unauthenticated_request_delay_ms' => 0,
        ]);
    }
}
