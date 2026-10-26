<?php

namespace Database\Factories;

use App\Models\CashSession;
use App\Models\CashSessionStatus;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<CashSession>
 */
class CashSessionFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'opened_by_id' => User::factory(),
            'opened_at' => now(),
            'opening_cash' => 1000,
            'status' => CashSessionStatus::Open,
            'open_lock' => 1,
        ];
    }

    /**
     * Indicate that the session was already closed.
     */
    public function closed(string $cashLeft = '1000.00'): static
    {
        return $this->state(fn (array $attributes) => [
            'closed_by_id' => $attributes['opened_by_id'],
            'closed_at' => now(),
            'expected_cash' => $cashLeft,
            'counted_cash' => $cashLeft,
            'cash_difference' => 0,
            'cash_left' => $cashLeft,
            'status' => CashSessionStatus::Closed,
            'open_lock' => null,
        ]);
    }
}
