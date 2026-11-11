<?php

namespace Tests\Feature;

use App\Models\BilliardTable;
use App\Models\Reservation;
use App\Models\ServiceRate;
use App\Models\TournamentRegistration;
use App\Services\ReservationService;
use Database\Seeders\CueLabDemoSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class CueLabDemoSeederTest extends TestCase
{
    use RefreshDatabase;

    public function test_demo_seeder_can_be_rerun_and_sample_codes_open_the_guest_status_pages(): void
    {
        $this->seed(CueLabDemoSeeder::class);
        $premium = ServiceRate::where('name', 'Demo premium table')->firstOrFail();
        foreach (['Demo Table 7', 'Demo Table 8'] as $name) {
            BilliardTable::factory()->for($premium)->create(['name' => $name, 'is_active' => false]);
        }
        $this->seed(CueLabDemoSeeder::class);

        foreach ([
            'users' => 4,
            'operating_hours' => 7,
            'service_rates' => 3,
            'billiard_tables' => 6,
            'reservations' => 24,
            'availability_blocks' => 6,
            'tournaments' => 5,
            'tournament_registrations' => 22,
            'contact_messages' => 12,
        ] as $table => $count) {
            $this->assertDatabaseCount($table, $count);
        }
        $this->assertTrue(app(ReservationService::class)->enabled());

        $booking = Reservation::where('status', 'pending')->firstOrFail();
        $this->post(route('booking.lookup'), ['reference' => $booking->reference])->assertRedirect(route('booking.status'));
        $this->get(route('booking.status'))->assertOk()->assertSee('Awaiting approval')->assertDontSee($booking->customer_name);

        $entry = TournamentRegistration::where('status', 'paid')->firstOrFail();
        $this->post(route('tournament.lookup'), ['reference' => $entry->reference])->assertRedirect(route('tournament.status'));
        $this->get(route('tournament.status'))->assertOk()->assertSee('Paid')->assertDontSee($entry->full_name);
    }
}
