<?php

namespace Database\Factories;

use App\Models\Customer;
use App\Models\Sale;
use App\Models\SaleStatus;
use App\Models\User;
use App\Models\Warehouse;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Sale>
 */
class SaleFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'folio' => fake()->unique()->bothify('V-#####'),
            'customer_id' => Customer::factory(),
            'warehouse_id' => Warehouse::factory()->sellable(),
            'user_id' => User::factory(),
            'status' => SaleStatus::Completed,
            'subtotal' => 1000,
            'tax_total' => 160,
            'total' => 1160,
            'sold_at' => now(),
        ];
    }
}
