<?php

namespace Database\Factories;

use App\Models\Supplier;
use App\Models\SupplierSettlementType;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Supplier>
 */
class SupplierFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'name' => fake()->company(),
            'settlement_type' => SupplierSettlementType::Consignment,
            'phone' => fake()->phoneNumber(),
            'email' => fake()->safeEmail(),
            'is_active' => true,
        ];
    }

    /**
     * Indicate that the supplier sells outright.
     */
    public function purchase(): static
    {
        return $this->state(fn (array $attributes) => [
            'settlement_type' => SupplierSettlementType::Purchase,
        ]);
    }

    /**
     * Indicate that the supplier lends product repaid in kind.
     */
    public function inKind(): static
    {
        return $this->state(fn (array $attributes) => [
            'settlement_type' => SupplierSettlementType::InKind,
        ]);
    }
}
