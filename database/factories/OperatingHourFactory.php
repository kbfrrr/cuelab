<?php

namespace Database\Factories;

use App\Models\OperatingHour;
use Illuminate\Database\Eloquent\Factories\Factory;

/** @extends Factory<OperatingHour> */
class OperatingHourFactory extends Factory
{
    public function definition(): array
    {
        return ['day_of_week' => fake()->unique()->numberBetween(0, 6), 'is_verified' => false];
    }
}
