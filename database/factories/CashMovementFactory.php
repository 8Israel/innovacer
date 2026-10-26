<?php

namespace Database\Factories;

use App\Models\CashMovement;
use App\Models\CashMovementType;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<CashMovement>
 */
class CashMovementFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'user_id' => User::factory(),
            'type' => CashMovementType::Outflow,
            'concept' => fake()->sentence(3),
            'beneficiary' => fake()->name(),
            'amount' => fake()->randomFloat(2, 50, 2000),
            'occurred_at' => now(),
        ];
    }
}
