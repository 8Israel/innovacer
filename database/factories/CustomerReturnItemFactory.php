<?php

namespace Database\Factories;

use App\Models\CustomerReturn;
use App\Models\CustomerReturnItem;
use App\Models\SaleItem;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<CustomerReturnItem>
 */
class CustomerReturnItemFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'customer_return_id' => CustomerReturn::factory(),
            'sale_item_id' => SaleItem::factory(),
            'product_id' => fn (array $attributes) => SaleItem::find($attributes['sale_item_id'])->product_id,
            'quantity' => 1,
            'refund_amount' => 580,
        ];
    }
}
