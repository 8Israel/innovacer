<?php

namespace Database\Factories;

use App\Models\InventoryOperation;
use App\Models\Lot;
use App\Models\Product;
use App\Models\Supplier;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Lot>
 */
class LotFactory extends Factory
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
            'supplier_id' => Supplier::factory(),
            'owner_supplier_id' => null,
            'inventory_operation_id' => InventoryOperation::factory(),
            'unit_cost' => fake()->randomFloat(2, 50, 1500),
            'quantity_received' => fake()->numberBetween(1, 50),
            'received_at' => now(),
        ];
    }
}
