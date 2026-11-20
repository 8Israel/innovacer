<?php

namespace Database\Factories;

use App\Models\Customer;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Customer>
 */
class CustomerFactory extends Factory
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
            'rfc' => fake()->regexify('[A-Z]{4}[0-9]{6}[A-Z0-9]{3}'),
            'email' => fake()->unique()->safeEmail(),
            'tax_system' => '601',
            'cfdi_use' => 'G03',
            'zip_code' => fake()->numerify('#####'),
        ];
    }

    /**
     * Indicate that the customer may buy on credit, without a limit unless one is given.
     */
    public function withCredit(?string $limit = null): static
    {
        return $this->state(fn (array $attributes) => [
            'credit_enabled' => true,
            'credit_limit' => $limit,
        ]);
    }
}
