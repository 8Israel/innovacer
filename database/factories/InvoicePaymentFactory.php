<?php

namespace Database\Factories;

use App\Models\CustomerPayment;
use App\Models\Invoice;
use App\Models\InvoicePayment;
use App\Models\InvoiceType;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<InvoicePayment>
 */
class InvoicePaymentFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'invoice_id' => Invoice::factory()->stamped()->state(['type' => InvoiceType::Payment]),
            'related_invoice_id' => Invoice::factory()->stamped(),
            'customer_payment_id' => CustomerPayment::factory(),
            'installment' => 1,
            'previous_balance' => 1000,
            'amount_paid' => 400,
            'outstanding_balance' => 600,
        ];
    }
}
