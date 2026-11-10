<?php

namespace Database\Factories;

use App\Models\BusinessSetting;
use Illuminate\Database\Eloquent\Factories\Factory;

/** @extends Factory<BusinessSetting> */
class BusinessSettingFactory extends Factory
{
    public function definition(): array
    {
        return ['key' => fake()->unique()->slug(), 'value' => null, 'is_verified' => false];
    }
}
