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
            ->assertDontSee('200.00')->assertDontSee('Hourly rental for any billiard table.')->assertDontSee('Retired rate');
        $this->get('/')->assertOk()->assertDontSee('Unapproved address');
    }

    public function test_home_links_to_the_same_venue_policy_on_rates(): void
    {
        $policy = 'CueLab sells snacks, non-alcoholic drinks, and alcoholic drinks. Guests may bring their own alcohol and rice meals. Rice meals are bring-your-own only. Ask CueLab staff where vaping is permitted.';

        $this->get(route('home'))->assertOk()
            ->assertSeeTextInOrder(['Plan your visit', $policy, 'House rules'])
            ->assertSee('href="'.route('rates').'#house-rules"', false);
        $this->get(route('rates'))->assertOk()
            ->assertSee('id="house-rules"', false)
            ->assertSeeTextInOrder(['House rules', $policy]);
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
        $this->get('/booking')->assertSee('No reservations are being accepted');
        $this->post('/contact', ['message' => 'test'])->assertStatus(405);
        $this->post('/booking')->assertStatus(419);
    }

    public function test_contact_shows_verified_details_and_directions_without_a_message_form(): void
    {
        BusinessSetting::factory()->create(['key' => 'address', 'value' => '2/F, MacArthur Hwy, Angeles', 'is_verified' => true]);
        BusinessSetting::factory()->create(['key' => 'phone', 'value' => '+63 (917) 555-0123', 'is_verified' => true]);
        BusinessSetting::factory()->create(['key' => 'social_url', 'value' => 'https://example.test/cuelab', 'is_verified' => true]);

        $page = $this->get('/contact')->assertOk()
            ->assertSeeTextInOrder(['Visit CueLab', '2/F, MacArthur Hwy, Angeles', 'Get directions', 'Call the venue', 'Social media'])
            ->assertSee('href="tel:+639175550123"', false)
            ->assertSee('href="https://example.test/cuelab"', false)
            ->assertDontSee('Leave a message')
            ->assertDontSee('Contact form preview');

        $mapsHref = 'href="https://www.google.com/maps/search/?api=1&amp;query=2%2FF%2C%20MacArthur%20Hwy%2C%20Angeles"';
        $this->assertSame(2, substr_count($page->getContent(), $mapsHref));
    }

    public function test_contact_falls_back_when_details_are_missing_or_unverified(): void
    {
        BusinessSetting::factory()->create(['key' => 'phone', 'value' => '09175550123', 'is_verified' => false]);
        BusinessSetting::factory()->create(['key' => 'social_url', 'value' => 'https://example.test/unverified', 'is_verified' => false]);

        $this->get('/contact')->assertOk()
            ->assertSee('Address pending verification')
            ->assertSee('Phone number pending verification')
            ->assertSee('Official social link pending verification')
            ->assertSee('query=The%20Cue%20Lab%20Billiards%2C%20Angeles%2C%20Pampanga')
            ->assertDontSee('tel:')
            ->assertDontSee('https://example.test/unverified');
    }

    public function test_homepage_shows_verified_overnight_hours_and_table_rate(): void
    {
        foreach (range(0, 6) as $day) {
            OperatingHour::factory()->create([
                'day_of_week' => $day,
                'is_verified' => true,
                'is_closed' => $day === 3,
                'opens_at' => $day === 3 ? null : '16:00:00',
                'closes_at' => $day === 3 ? null : '02:00:00',
            ]);
        }
        ServiceRate::factory()->create(['amount_minor' => 20000, 'unit' => 'table/hour', 'currency' => 'PHP', 'is_verified' => true]);

        $this->get('/')->assertOk()->assertSee('Thursday–Tuesday')->assertSee('4:00 PM–2:00 AM the next day')->assertSee('₱200');
    }
}
