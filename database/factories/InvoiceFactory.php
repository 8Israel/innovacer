<?php

namespace Database\Factories;

use App\Models\Customer;
use App\Models\Invoice;
use App\Models\InvoiceStatus;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Invoice>
 */
class InvoiceFactory extends Factory
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
            'status' => InvoiceStatus::Draft,
            'payment_form' => '01',
            'payment_method' => 'PUE',
            'cfdi_use' => 'G03',
            'currency' => 'MXN',
        ];
    }

    public function stamped(): static
    {
        return $this->state(fn (array $attributes) => [
            'status' => InvoiceStatus::Stamped,
            'facturapi_id' => fake()->uuid(),
            'stamped_at' => now(),
        ]);
    }
}
