<?php

namespace Database\Factories;

use App\Models\CustomerReturn;
use App\Models\RefundMethod;
use App\Models\Sale;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<CustomerReturn>
 */
class CustomerReturnFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'folio' => fake()->unique()->bothify('D-#####'),
            'sale_id' => Sale::factory(),
            'customer_id' => fn (array $attributes) => Sale::find($attributes['sale_id'])->customer_id,
            'warehouse_id' => fn (array $attributes) => Sale::find($attributes['sale_id'])->warehouse_id,
            'user_id' => User::factory(),
            'refund_method' => RefundMethod::Cash,
            'refund_amount' => 580,
            'reason' => fake()->sentence(),
            'returned_at' => now(),
        ];
    }
}
