<?php

namespace Tests\Feature;

use App\Models\BilliardTable;
use App\Models\BusinessSetting;
use App\Models\Reservation;
use App\Models\ServiceRate;
use App\Models\StaffAction;
use App\Models\Tournament;
use App\Models\TournamentRegistration;
use Database\Seeders\BusinessSettingsSeeder;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class CueLabSchemaTest extends TestCase
{
    use RefreshDatabase;

    public function test_seed_is_safe_to_repeat_and_does_not_overwrite_verified_values(): void
    {
        $this->seed(BusinessSettingsSeeder::class);
        BusinessSetting::where('key', 'phone')->first()->update(['value' => 'Approved contact', 'is_verified' => true]);
        $this->seed(BusinessSettingsSeeder::class);
        $this->assertDatabaseCount('service_rates', 1);
        $this->assertDatabaseCount('operating_hours', 7);
        $this->assertDatabaseCount('users', 0);
        $this->assertSame('Approved contact', BusinessSetting::where('key', 'phone')->first()->value);
        $this->assertFalse(BusinessSetting::where('key', 'booking_enabled')->first()->value);
        $this->assertSame(60, BusinessSetting::where('key', 'hold_minutes')->first()->value);
        $this->assertFalse(ServiceRate::first()->is_verified);
    }

    public function test_reservations_keep_price_snapshots_when_rates_change(): void
    {
        $rate = ServiceRate::factory()->create();
        $table = BilliardTable::factory()->for($rate)->create();
        $reservation = Reservation::factory()->for($table)->create();
        $rate->update(['amount_minor' => 9900]);
        $this->assertSame(3000, $reservation->fresh()->quoted_hourly_rate_minor);
        $this->assertSame($table->id, $reservation->billiardTable->id);
        $this->assertArrayNotHasKey('phone', $reservation->toArray());
    }

    public function test_reference_codes_are_unique_in_storage(): void
    {
        $reservation = Reservation::factory()->create();
        $this->expectException(QueryException::class);
        Reservation::factory()->create(['reference' => $reservation->reference]);
    }

    public function test_tables_with_reservation_history_cannot_be_deleted(): void
    {
        $reservation = Reservation::factory()->create();
        $this->expectException(QueryException::class);
        $reservation->billiardTable->delete();
    }

    public function test_unpaid_registration_does_not_imply_confirmed_entry(): void
    {
        $event = Tournament::factory()->create();
        $registration = TournamentRegistration::factory()->for($event)->create();
        $this->assertSame('unpaid', $registration->fresh()->status);
        $this->assertNull($registration->paid_at);
        $this->assertSame(1, $event->registrations()->count());
    }

    public function test_staff_history_survives_removing_its_author(): void
    {
        $table = BilliardTable::factory()->create();
        $action = StaffAction::factory()->create(['subject_type' => BilliardTable::class, 'subject_id' => $table->id]);
        $action->user->delete();

        $this->assertNull($action->fresh()->user_id);
        $this->assertTrue($action->subject->is($table));
        $this->assertDatabaseHas('staff_actions', ['id' => $action->id]);
    }
}
