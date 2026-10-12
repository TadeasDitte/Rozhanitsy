<?php

namespace Database\Factories;

use App\Models\CnaProductName;
use App\Models\ParsedRecord;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<CnaProductName>
 */
class CnaProductNameFactory extends Factory
{
    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'parsed_record_id' => ParsedRecord::factory(),
            'cna_vendor' => fake()->word(),
            'cna_product' => fake()->word(),
            'vendor' => fake()->word(),
            'product' => fake()->word(),
        ];
    }
}
