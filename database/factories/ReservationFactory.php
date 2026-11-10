<?php

namespace Database\Factories;

use App\Models\BilliardTable;
use App\Models\Reservation;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/** @extends Factory<Reservation> */
class ReservationFactory extends Factory
{
    public function definition(): array
    {
        return ['reference' => (string) Str::uuid(), 'submission_key' => (string) Str::uuid(), 'billiard_table_id' => BilliardTable::factory(), 'customer_name' => fake()->name(), 'phone' => '09171234567', 'starts_at' => now()->addDay(), 'ends_at' => now()->addDay()->addHour(), 'hold_expires_at' => now()->addHour(), 'quoted_hourly_rate_minor' => 3000, 'quoted_total_minor' => 3000];
    }
}
