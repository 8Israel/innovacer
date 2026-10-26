<?php

namespace Database\Factories;

use App\Models\InventoryOperation;
use App\Models\LoanEntry;
use App\Models\Product;
use App\Models\Supplier;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<LoanEntry>
 */
class LoanEntryFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'lender_id' => Supplier::factory()->inKind(),
            'product_id' => Product::factory(),
            'inventory_operation_id' => InventoryOperation::factory(),
            'quantity' => fake()->numberBetween(1, 10),
        ];
    }
}
