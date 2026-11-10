<?php

namespace Database\Factories;

use App\Models\AvailabilityBlock;
use App\Models\BilliardTable;
use Illuminate\Database\Eloquent\Factories\Factory;

/** @extends Factory<AvailabilityBlock> */
class AvailabilityBlockFactory extends Factory
{
    public function definition(): array
    {
        return ['billiard_table_id' => BilliardTable::factory(), 'type' => 'maintenance', 'reason' => 'Maintenance', 'starts_at' => now()->addDay(), 'ends_at' => now()->addDay()->addHour()];
    }
}
