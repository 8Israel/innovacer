<?php

namespace Database\Factories;

use App\Models\Product;
use App\Models\StockMinimum;
use App\Models\Warehouse;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<StockMinimum>
 */
class StockMinimumFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'product_id' => Product::factory(),
            'warehouse_id' => Warehouse::factory(),
            'min_quantity' => fake()->numberBetween(1, 10),
        ];
    }
}
