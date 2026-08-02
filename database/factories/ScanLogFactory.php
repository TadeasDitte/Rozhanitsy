<?php

namespace Database\Factories;

use App\Models\ScanHost;
use App\Models\ScanLog;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<ScanLog>
 */
class ScanLogFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'scan_host_id' => ScanHost::factory(),
            'tenant_id' => null,
            'component_count' => fake()->numberBetween(1, 200),
            'vulnerable_count' => 0,
            'unmatched_count' => 0,
            'scanned_at' => now(),
        ];
    }

    public function forTenant(string $tenantId): static
    {
        return $this->state(fn (array $attributes): array => ['tenant_id' => $tenantId]);
    }
}
