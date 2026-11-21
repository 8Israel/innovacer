<?php

namespace Database\Factories;

use App\Models\PhysicalCount;
use App\Models\PhysicalCountItem;
use App\Models\Product;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<PhysicalCountItem>
 */
class PhysicalCountItemFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'physical_count_id' => PhysicalCount::factory(),
            'product_id' => Product::factory(),
            'system_quantity' => fake()->numberBetween(0, 30),
            'counted_quantity' => null,
        ];
    }
}
