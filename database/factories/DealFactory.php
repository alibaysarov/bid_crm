<?php

namespace Database\Factories;

use App\Enums\DealStatus;
use App\Models\Deal;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Deal>
 */
class DealFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'external_id' => null,
            'title' => fake()->sentence(3),
            'status' => fake()->randomElement(DealStatus::values()),
            'amount' => fake()->randomFloat(2, 0, 1_000_000),
            'external_updated_at' => null,
            'external_version' => null,
            'created_by_user_id' => null,
            'assigned_to_user_id' => null,
        ];
    }
}
