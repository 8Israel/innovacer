<?php

namespace Database\Factories;

use App\Models\InventoryMovement;
use App\Models\InventoryOperation;
use App\Models\Lot;
use App\Models\Warehouse;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<InventoryMovement>
 */
class InventoryMovementFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'inventory_operation_id' => InventoryOperation::factory(),
            'warehouse_id' => Warehouse::factory(),
            'lot_id' => Lot::factory(),
            'product_id' => fn (array $attributes) => Lot::find($attributes['lot_id'])->product_id,
            'quantity' => 10,
            'stock_before' => 0,
            'stock_after' => 10,
        ];
    }
}
