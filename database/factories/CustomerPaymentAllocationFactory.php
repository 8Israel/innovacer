<?php

namespace Database\Factories;

use App\Models\CustomerPayment;
use App\Models\CustomerPaymentAllocation;
use App\Models\Sale;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<CustomerPaymentAllocation>
 */
class CustomerPaymentAllocationFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'customer_payment_id' => CustomerPayment::factory(),
            'sale_id' => Sale::factory(),
            'amount' => fake()->randomFloat(2, 100, 2000),
        ];
    }
}
