<?php

namespace Database\Factories;

use App\Models\Tournament;
use Illuminate\Database\Eloquent\Factories\Factory;

/** @extends Factory<Tournament> */
class TournamentFactory extends Factory
{
    public function definition(): array
    {
        return ['title' => fake()->words(3, true), 'starts_at' => now()->addWeek(), 'ends_at' => now()->addWeek()->addHours(4), 'capacity' => 16, 'status' => 'draft'];
    }
}
