<?php

namespace Database\Factories;

use App\Models\ScanHost;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<ScanHost>
 */
class ScanHostFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'hostname' => fake()->unique()->domainWord().'.example.com',
            'is_active' => true,
            'last_seen_at' => null,
        ];
    }

    public function inactive(): static
    {
        return $this->state(fn (array $attributes): array => ['is_active' => false]);
    }
}
