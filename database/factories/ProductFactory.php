<?php

namespace Database\Factories;

use App\Models\Product;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Product>
 */
class ProductFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'name' => fake()->words(3, true),
            'description' => fake()->sentence(),
            'sku' => fake()->unique()->bothify('AGR-####'),
            'barcode' => fake()->unique()->ean13(),
            'unit_price' => fake()->randomFloat(2, 50, 2000),
            'tax_rate' => 0.16,
            'product_key' => '10191509',
            'product_key_name' => 'Insecticidas',
            'unit_key' => 'H87',
            'unit_name' => 'Pieza',
        ];
    }

    /**
     * Indicate that the product has no price yet.
     */
    public function withoutPrice(): static
    {
        return $this->state(fn (array $attributes) => [
            'unit_price' => null,
        ]);
    }
}
