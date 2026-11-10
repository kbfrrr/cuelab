<?php

namespace Database\Factories;

use App\Models\ContactMessage;
use Illuminate\Database\Eloquent\Factories\Factory;

/** @extends Factory<ContactMessage> */
class ContactMessageFactory extends Factory
{
    public function definition(): array
    {
        return ['full_name' => fake()->name(), 'phone' => '09171234567', 'subject' => 'Venue inquiry', 'message' => fake()->sentence()];
    }
}
