<?php

namespace Database\Factories;

use App\Models\InventoryOperation;
use App\Models\InventoryOperationType;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<InventoryOperation>
 */
class InventoryOperationFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'type' => InventoryOperationType::Entry,
            'user_id' => User::factory(),
            'note' => fake()->sentence(),
            'occurred_at' => now(),
        ];
    }
}
