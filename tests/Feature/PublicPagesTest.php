<?php

namespace Tests\Feature;

use App\Models\BusinessSetting;
use App\Models\OperatingHour;
use App\Models\ServiceRate;
use Database\Seeders\BusinessSettingsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

class PublicPagesTest extends TestCase
{
    use RefreshDatabase;

    public function test_all_public_pages_include_navigation_privacy_and_ai_disclosure(): void
    {
        foreach (['/', '/about', '/rates', '/contact', '/privacy', '/booking', '/tournaments'] as $path) {
            $this->get($path)->assertOk()->assertSee('CueLab')->assertSee('AI assistant')
                ->assertSee('Privacy notice')->assertSee('Skip to content');
        }
    }

    public function test_only_verified_active_rates_and_verified_business_details_are_published(): void
    {
        $this->seed(BusinessSettingsSeeder::class);
        BusinessSetting::where('key', 'address')->update(['value' => json_encode('Unapproved address')]);
        ServiceRate::factory()->create(['name' => 'Approved rate', 'amount_minor' => 12500, 'is_verified' => true]);
        ServiceRate::factory()->create(['name' => 'Retired rate', 'is_verified' => true, 'is_active' => false]);
        $this->get('/rates')->assertOk()->assertSee('Approved rate')->assertSee('125.00')
            ->assertDontSee('30.00')->assertDontSee('Unverified draft')->assertDontSee('Retired rate');
        $this->get('/')->assertOk()->assertDontSee('Unapproved address');
    }

    public function test_homepage_remains_available_when_business_storage_fails(): void
    {
        Schema::drop('business_settings');
        $this->get('/')->assertOk()->assertSee('Live venue information is temporarily unavailable.');
    }

    public function test_hours_require_complete_verified_information_but_closed_days_need_no_times(): void
    {
        OperatingHour::factory()->create(['day_of_week' => 1, 'is_verified' => true, 'opens_at' => '12:00', 'closes_at' => null]);
        OperatingHour::factory()->create(['day_of_week' => 2, 'is_verified' => false, 'opens_at' => '12:00', 'closes_at' => '22:00']);
        OperatingHour::factory()->create(['day_of_week' => 3, 'is_verified' => true, 'opens_at' => '12:00', 'closes_at' => '22:00']);
        OperatingHour::factory()->create(['day_of_week' => 4, 'is_verified' => true, 'is_closed' => true]);

        $this->get('/rates')->assertOk()->assertDontSee('Monday')->assertDontSee('Tuesday')
            ->assertSee('Wednesday')->assertSee('12:00')->assertSee('22:00')->assertSee('Thursday')->assertSee('Closed');
    }

    public function test_unimplemented_submissions_are_not_accepted(): void
    {
        $this->get('/contact')->assertSee('Messages cannot be submitted yet.')->assertSee('fieldset disabled', false);
        $this->get('/booking')->assertSee('No reservations are being accepted');
        $this->post('/contact', ['message' => 'test'])->assertStatus(405);
        $this->post('/booking')->assertStatus(405);
    }
}
