<?php

namespace Database\Factories;

use App\Models\Lot;
use App\Models\Stock;
use App\Models\Warehouse;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Stock>
 */
class StockFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'warehouse_id' => Warehouse::factory(),
            'lot_id' => Lot::factory(),
            'product_id' => fn (array $attributes) => Lot::find($attributes['lot_id'])->product_id,
            'quantity' => fake()->numberBetween(1, 50),
        ];
    }
}
