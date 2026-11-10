<?php

namespace Database\Factories;

use App\Models\BilliardTable;
use Illuminate\Database\Eloquent\Factories\Factory;

/** @extends Factory<BilliardTable> */
class BilliardTableFactory extends Factory
{
    public function definition(): array
    {
        return ['name' => fake()->unique()->bothify('Table ##??'), 'type' => 'pool', 'is_active' => true];
    }
}
