<?php

namespace Database\Factories;

use App\Models\Invoice;
use App\Models\InvoiceItem;
use App\Models\Product;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<InvoiceItem>
 */
class InvoiceItemFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        $quantity = fake()->randomFloat(2, 1, 20);
        $unitPrice = fake()->randomFloat(2, 50, 2000);
        $taxRate = 0.16;
        $subtotal = round($quantity * $unitPrice, 2);
        $taxAmount = round($subtotal * $taxRate, 2);

        return [
            'invoice_id' => Invoice::factory(),
            'product_id' => Product::factory(),
            'quantity' => $quantity,
            'unit_price' => $unitPrice,
            'tax_rate' => $taxRate,
            'subtotal' => $subtotal,
            'tax_amount' => $taxAmount,
            'total' => $subtotal + $taxAmount,
        ];
    }
}
