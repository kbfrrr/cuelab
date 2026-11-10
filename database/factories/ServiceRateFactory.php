<?php

namespace Database\Factories;

use App\Models\ServiceRate;
use Illuminate\Database\Eloquent\Factories\Factory;

/** @extends Factory<ServiceRate> */
class ServiceRateFactory extends Factory
{
    public function definition(): array
    {
        return ['name' => fake()->unique()->words(3, true), 'amount_minor' => 3000, 'currency' => 'PHP', 'is_verified' => false];
    }
}
