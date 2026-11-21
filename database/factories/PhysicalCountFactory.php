<?php

namespace Database\Factories;

use App\Models\PhysicalCount;
use App\Models\PhysicalCountStatus;
use App\Models\User;
use App\Models\Warehouse;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<PhysicalCount>
 */
class PhysicalCountFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'warehouse_id' => Warehouse::factory(),
            'started_by_id' => User::factory(),
            'started_at' => now(),
            'status' => PhysicalCountStatus::InProgress,
        ];
    }
}
