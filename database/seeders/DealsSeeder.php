<?php

namespace Database\Seeders;

use App\Models\Deal;
use App\Models\User;
use Illuminate\Database\Seeder;
use LogicException;

class DealsSeeder extends Seeder
{
    /**
     * Run the database seeds.
     */
    public function run(): void
    {
        $userIds = User::query()->pluck('id')->all();

        if ($userIds === []) {
            throw new LogicException('Users must be seeded before deals.');
        }

        Deal::factory()
            ->count(30)
            ->state(fn (array $attributes): array => [
                'created_by_user_id' => fake()->randomElement($userIds),
                'assigned_to_user_id' => fake()->randomElement($userIds),
            ])
            ->create();
    }
}
