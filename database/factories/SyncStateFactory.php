<?php

namespace Database\Factories;

use App\Models\Source;
use App\Models\SyncState;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<SyncState>
 */
class SyncStateFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'source_id' => Source::factory()->nvd(),
            'last_synced_at' => null,
            'last_index' => null,
        ];
    }

    public function syncedAt(string $when): static
    {
        return $this->state(fn (array $attributes): array => ['last_synced_at' => $when]);
    }
}
