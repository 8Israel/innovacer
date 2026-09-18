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
}
