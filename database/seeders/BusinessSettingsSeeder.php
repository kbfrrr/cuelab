<?php

namespace Database\Seeders;

use App\Models\BusinessSetting;
use App\Models\OperatingHour;
use App\Models\ServiceRate;
use Illuminate\Database\Seeder;

class BusinessSettingsSeeder extends Seeder
{
    public function run(): void
    {
        foreach (['booking_enabled' => false, 'hold_minutes' => 60, 'address' => null, 'phone' => null, 'social_url' => null, 'house_rules' => null] as $key => $value) {
            BusinessSetting::firstOrCreate(['key' => $key], ['value' => $value, 'is_verified' => in_array($key, ['booking_enabled', 'hold_minutes'])]);
        }
        ServiceRate::firstOrCreate(['name' => 'Table rental'], ['amount_minor' => 3000, 'description' => 'Unverified draft from the project brief. Do not publish until approved.', 'is_verified' => false]);
        foreach (range(0, 6) as $day) {
            OperatingHour::firstOrCreate(['day_of_week' => $day], ['is_verified' => false]);
        }
    }
}
