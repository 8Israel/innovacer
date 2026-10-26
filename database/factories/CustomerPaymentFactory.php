<?php

namespace Database\Factories;

use App\Models\Customer;
use App\Models\CustomerPayment;
use App\Models\PaymentMethod;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<CustomerPayment>
 */
class CustomerPaymentFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'customer_id' => Customer::factory(),
            'user_id' => User::factory(),
            'method' => PaymentMethod::Transfer,
            'amount' => fake()->randomFloat(2, 100, 5000),
            'reference' => fake()->bothify('REF-######'),
            'paid_at' => now(),
        ];
    }
}
