<?php

namespace Database\Factories;

use App\Models\Lot;
use App\Models\SupplierPayment;
use App\Models\SupplierPaymentAllocation;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<SupplierPaymentAllocation>
 */
class SupplierPaymentAllocationFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'supplier_payment_id' => SupplierPayment::factory(),
            'lot_id' => Lot::factory(),
            'quantity' => 5,
            'amount' => 1500,
        ];
    }
}
