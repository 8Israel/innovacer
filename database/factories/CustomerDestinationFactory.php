<?php

namespace Database\Factories;

use App\Models\Customer;
use App\Models\CustomerDestination;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<CustomerDestination>
 */
class CustomerDestinationFactory extends Factory
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
            'name' => fake()->city(),
            'is_active' => true,
        ];
    }
}
