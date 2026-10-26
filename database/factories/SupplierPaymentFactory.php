<?php

namespace Database\Factories;

use App\Models\PaymentMethod;
use App\Models\Supplier;
use App\Models\SupplierPayment;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<SupplierPayment>
 */
class SupplierPaymentFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'supplier_id' => Supplier::factory(),
            'user_id' => User::factory(),
            'method' => PaymentMethod::Transfer,
            'amount' => fake()->randomFloat(2, 1000, 100000),
            'paid_at' => now(),
        ];
    }
}
