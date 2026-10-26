<?php

namespace Database\Factories;

use App\Models\PriceType;
use App\Models\Product;
use App\Models\Sale;
use App\Models\SaleItem;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<SaleItem>
 */
class SaleItemFactory extends Factory
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
            'product_id' => Product::factory(),
            'quantity' => 2,
            'price_type' => PriceType::Public,
            'unit_price' => 500,
            'subtotal' => 1000,
            'iva_rate' => 0.16,
            'iva_amount' => 160,
            'total' => 1160,
        ];
    }
}
