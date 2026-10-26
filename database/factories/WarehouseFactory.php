<?php

namespace Database\Factories;

use App\Models\Supplier;
use App\Models\Warehouse;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Warehouse>
 */
class WarehouseFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'name' => fake()->words(2, true),
            'code' => fake()->unique()->bothify('ALM-###'),
            'lender_id' => null,
            'is_sellable' => false,
            'is_active' => true,
        ];
    }

    /**
     * Indicate that the warehouse is the sales floor.
     */
    public function sellable(): static
    {
        return $this->state(fn (array $attributes) => [
            'is_sellable' => true,
        ]);
    }

    /**
     * Indicate that the warehouse holds product lent in kind.
     */
    public function lentBy(?Supplier $lender = null): static
    {
        return $this->state(fn (array $attributes) => [
            'lender_id' => $lender ?? Supplier::factory()->inKind(),
        ]);
    }
}
