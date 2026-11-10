<?php

namespace Database\Factories;

use App\Models\StaffAction;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/** @extends Factory<StaffAction> */
class StaffActionFactory extends Factory
{
    public function definition(): array
    {
        return ['user_id' => User::factory(), 'action' => 'settings.updated', 'metadata' => []];
    }
}
