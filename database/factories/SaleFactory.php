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
            'folio' => sprintf('V-%06d', fake()->unique()->numberBetween(1, 999999)),
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

    /**
     * Indicate that the sale was held to be charged later.
     */
    public function pending(): static
    {
        return $this->state(fn (array $attributes) => [
            'folio' => null,
            'status' => SaleStatus::Pending,
            'sold_at' => null,
            'held_at' => now(),
        ]);
    }
}
