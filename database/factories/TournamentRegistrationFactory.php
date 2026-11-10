<?php

namespace Database\Factories;

use App\Models\Tournament;
use App\Models\TournamentRegistration;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/** @extends Factory<TournamentRegistration> */
class TournamentRegistrationFactory extends Factory
{
    public function definition(): array
    {
        return ['tournament_id' => Tournament::factory(), 'reference' => (string) Str::uuid(), 'submission_key' => (string) Str::uuid(), 'full_name' => fake()->name(), 'phone' => '09171234567', 'quoted_fee_minor' => 0];
    }
}
