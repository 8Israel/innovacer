<?php

namespace Database\Factories;

use App\Models\PaymentMethod;
use App\Models\Sale;
use App\Models\SalePayment;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<SalePayment>
 */
class SalePaymentFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'sale_id' => Sale::factory(),
            'method' => PaymentMethod::Cash,
            'amount' => 1160,
            'received_amount' => 1200,
            'change_amount' => 40,
        ];
    }
}
