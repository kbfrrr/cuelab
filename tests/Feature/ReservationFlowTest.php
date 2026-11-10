<?php

namespace Tests\Feature;

use App\Filament\Resources\Reservations\Pages\ManageReservations;
use App\Models\BilliardTable;
use App\Models\BusinessSetting;
use App\Models\OperatingHour;
use App\Models\Reservation;
use App\Models\ServiceRate;
use App\Models\User;
use App\Services\ReservationService;
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Livewire\Livewire;
use Symfony\Component\HttpKernel\Exception\HttpException;
use Tests\TestCase;

class ReservationFlowTest extends TestCase
{
    use RefreshDatabase;

    private BilliardTable $table;

    private ReservationService $service;

    protected function setUp(): void
    {
        parent::setUp();
        $this->travelTo(now()->startOfDay()->addHours(8));
        BusinessSetting::create(['key' => 'booking_enabled', 'value' => true, 'is_verified' => true]);
        BusinessSetting::create(['key' => 'hold_minutes', 'value' => 60, 'is_verified' => true]);
        foreach (range(0, 6) as $day) {
            OperatingHour::create(['day_of_week' => $day, 'opens_at' => '09:00', 'closes_at' => '23:00', 'is_verified' => true]);
        }
        $rate = ServiceRate::factory()->create(['amount_minor' => 3000, 'is_verified' => true]);
        $this->table = BilliardTable::factory()->for($rate)->create();
        $this->service = app(ReservationService::class);
    }

    private function data(array $overrides = []): array
    {
        return [...['billiard_table_id' => $this->table->id, 'starts_at' => now()->setTime(12, 0)->format('Y-m-d H:i:s'), 'minutes' => 60, 'customer_name' => 'Sample Guest', 'phone' => '09171234567', 'hourly_rate' => 3000, 'submission_key' => (string) Str::uuid()], ...$overrides];
    }

    private function staff(): User
    {
        return User::factory()->create(['is_staff' => true]);
    }

    public function test_guest_can_review_submit_and_privately_lookup_a_booking(): void
    {
        $data = $this->data(['starts_at' => now()->setHour(12)->format('Y-m-d\TH:i'), 'consent' => '1']);
        $this->get('/booking?'.http_build_query(['starts_at' => $data['starts_at'], 'minutes' => 60]))->assertOk()->assertSee($this->table->name);
        $this->post('/booking/review', $data)->assertOk()->assertSee('30.00');
        $draft = session('booking_draft');
        $this->post('/booking', ['submission_key' => $draft['submission_key']])->assertRedirect(route('booking.status'));
        $record = Reservation::first();
        $this->assertSame('pending', $record->status);
        $this->assertSame(3000, $record->quoted_total_minor);
        $this->get('/booking/status')->assertOk()->assertSee('Awaiting approval.')->assertDontSee('Sample Guest')->assertDontSee('09171234567')->assertHeader('Referrer-Policy', 'no-referrer');
        $this->post('/booking/lookup', ['reference' => $record->reference])->assertRedirect(route('booking.status'));
        $this->post('/booking', ['submission_key' => $draft['submission_key']])->assertRedirect(route('booking.status'));
        $this->assertDatabaseCount('reservations', 1);
    }

    public function test_price_change_requires_another_review_without_booking(): void
    {
        $this->post('/booking/review', $this->data(['starts_at' => now()->setHour(12)->format('Y-m-d\TH:i'), 'consent' => '1']))->assertOk();
        $this->table->serviceRate->update(['amount_minor' => 5000]);
        $this->post('/booking', ['submission_key' => session('booking_draft.submission_key')])->assertSessionHasErrors('booking')->assertRedirectContains('/booking?');
        $this->assertDatabaseCount('reservations', 0);
    }

    public function test_pending_holds_block_overlaps_but_allow_adjacent_times(): void
    {
        $this->service->create($this->data());
        $this->assertCount(1, $this->service->available(now()->setHour(13)->toDateTimeString(), 60));
        $this->expectException(ValidationException::class);
        $this->service->create($this->data());
    }

    public function test_expiry_releases_slots_and_expired_requests_cannot_be_approved(): void
    {
        $record = $this->service->create($this->data());
        $this->travel(61)->minutes();
        $this->assertCount(1, $this->service->available($record->starts_at->toDateTimeString(), 60));
        $this->service->expire();
        $this->assertSame('expired', $record->fresh()->status);
        $this->expectException(ValidationException::class);
        $this->service->transition($record, 'confirmed', $this->staff());
    }

    public function test_staff_can_approve_and_cancel_and_quotes_are_preserved(): void
    {
        $staff = $this->staff();
        $this->actingAs($staff);
        $record = $this->service->create($this->data());
        $this->table->serviceRate->update(['amount_minor' => 5000]);
        $this->service->transition($record, 'confirmed', $staff);
        $this->assertSame('confirmed', $record->fresh()->status);
        $this->assertSame(3000, $record->fresh()->quoted_total_minor);
        $this->service->transition($record, 'cancelled', $staff);
        $this->assertCount(1, $this->service->available($record->starts_at->toDateTimeString(), 60));
        $this->assertDatabaseHas('staff_actions', ['action' => 'reservations.updated', 'user_id' => $staff->id]);
    }

    public function test_rejection_releases_hold_and_walk_ins_block_availability(): void
    {
        $staff = $this->staff();
        $record = $this->service->create($this->data());
        $this->service->transition($record, 'rejected', $staff);
        $walkIn = $this->service->create($this->data(), $staff);
        $this->assertSame('confirmed', $walkIn->status);
        $this->assertSame('walk_in', $walkIn->source);
        $this->assertCount(0, $this->service->available($walkIn->starts_at->toDateTimeString(), 60));
    }

    public function test_closures_block_all_tables_and_cannot_override_existing_bookings(): void
    {
        $staff = $this->staff();
        $start = now()->setHour(12);
        $block = $this->service->block(['type' => 'closure', 'reason' => 'Closed', 'starts_at' => $start->toDateTimeString(), 'ends_at' => $start->copy()->addHour()->toDateTimeString()], $staff);
        $this->assertCount(0, $this->service->available($start->toDateTimeString(), 60));
        $block->delete();
        $this->service->create($this->data());
        $this->expectException(ValidationException::class);
        $this->service->block(['type' => 'maintenance', 'reason' => 'Maintenance', 'billiard_table_id' => $this->table->id, 'starts_at' => $start->toDateTimeString(), 'ends_at' => $start->copy()->addHour()->toDateTimeString()], $staff);
    }

    public function test_unverified_rates_and_disabled_booking_cannot_be_bypassed(): void
    {
        $this->table->serviceRate->update(['is_verified' => false]);
        $this->assertFalse($this->service->enabled());
        $this->expectException(ValidationException::class);
        $this->service->create($this->data());
    }

    public function test_booking_must_fit_opening_hours(): void
    {
        $this->expectException(ValidationException::class);
        $this->service->create($this->data(['starts_at' => now()->setHour(22)->toDateTimeString(), 'minutes' => 120]));
    }

    public function test_hold_cannot_extend_past_start_time(): void
    {
        $this->travelTo(now()->setHour(11)->setMinute(45));
        $record = $this->service->create($this->data());
        $this->assertTrue($record->hold_expires_at->equalTo($record->starts_at));
    }

    public function test_status_requires_a_private_code_and_lookup_is_throttled(): void
    {
        $this->get('/booking/status')->assertRedirect(route('booking'));
        for ($i = 0; $i < 10; $i++) {
            $this->post('/booking/lookup', ['reference' => str_repeat('z', 32)])->assertSessionHasErrors('reference');
        }
        $this->post('/booking/lookup', ['reference' => str_repeat('z', 32)])->assertStatus(429);
    }

    public function test_staff_can_approve_through_the_dashboard(): void
    {
        $record = $this->service->create($this->data());
        $this->actingAs($this->staff());
        Filament::setCurrentPanel(Filament::getPanel('admin'));
        Livewire::test(ManageReservations::class)
            ->callTableAction('confirmed', $record)->assertHasNoErrors();
        $this->assertSame('confirmed', $record->fresh()->status);
    }

    public function test_non_staff_cannot_approve_even_by_calling_the_service_directly(): void
    {
        $record = $this->service->create($this->data());
        $this->expectException(HttpException::class);
        $this->service->transition($record, 'confirmed', User::factory()->create());
    }

    public function test_overnight_hours_allow_booking_after_midnight(): void
    {
        OperatingHour::query()->update(['opens_at' => '18:00', 'closes_at' => '02:00']);
        $record = $this->service->create($this->data(['starts_at' => now()->addDay()->setTime(1, 0)->toDateTimeString()]));
        $this->assertSame('02:00', $record->ends_at->format('H:i'));
    }

    public function test_invalid_availability_search_returns_to_a_usable_form(): void
    {
        $this->get('/booking?'.http_build_query(['starts_at' => now()->setHour(23)->format('Y-m-d\TH:i'), 'minutes' => 60]))
            ->assertRedirect(route('booking'))->assertSessionHasErrors('booking');
        $this->withCookie(config('session.cookie'), session()->getId())
            ->get('/booking')->assertOk()->assertSee('outside verified opening hours');
    }

    public function test_staff_records_walk_ins_through_the_dashboard(): void
    {
        $this->actingAs($this->staff());
        Filament::setCurrentPanel(Filament::getPanel('admin'));
        Livewire::test(ManageReservations::class)
            ->callAction('walk_in', $this->data())->assertHasNoErrors();
        $this->assertDatabaseHas('reservations', ['source' => 'walk_in', 'status' => 'confirmed']);
    }
}
