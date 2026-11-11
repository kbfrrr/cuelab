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
use Carbon\CarbonImmutable;
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Livewire\Livewire;
use Tests\TestCase;

class ReservationArrivalTest extends TestCase
{
    use RefreshDatabase;

    private ReservationService $service;

    private Reservation $booking;

    private User $staff;

    private BilliardTable $table;

    protected function setUp(): void
    {
        parent::setUp();

        $this->travelTo($this->time('14:00'));
        $this->staff = User::factory()->create(['is_staff' => true]);
        $this->service = app(ReservationService::class);
        $this->table = BilliardTable::factory()->for(ServiceRate::factory()->state(['amount_minor' => 20000, 'is_verified' => true]))->create();
        BusinessSetting::create(['key' => 'booking_enabled', 'value' => true, 'is_verified' => true]);
        foreach (range(0, 6) as $day) {
            OperatingHour::create(['day_of_week' => $day, 'opens_at' => '12:00', 'closes_at' => '02:00', 'is_verified' => true]);
        }

        $this->booking = $this->confirmedBooking($this->time('14:00'));
    }

    public function test_grace_and_waiting_walk_in_are_required_to_record_a_call(): void
    {
        $this->travelTo($this->time('14:14'));
        $this->assertBlocked(fn () => $this->service->recordLateCall($this->booking, 'declined', true, $this->staff));
        $this->assertBlocked(fn () => $this->service->releaseNoShow($this->booking, true, $this->staff));

        $this->travelTo($this->time('14:15'));
        $this->assertBlocked(fn () => $this->service->recordLateCall($this->booking, 'declined', false, $this->staff));
        $this->assertTrue($this->service->conflicts($this->table->id, $this->time('14:15'), $this->time('15:00')));
        $this->service->recordLateCall($this->booking, 'declined', true, $this->staff);
        $this->assertSame('cancelled', $this->booking->fresh()->status);
        $this->assertFalse($this->service->conflicts($this->table->id, $this->time('14:15'), $this->time('15:00')));
    }

    public function test_staff_can_see_arrival_actions_before_they_are_available(): void
    {
        $this->actingAs($this->staff);
        Filament::setCurrentPanel(Filament::getPanel('admin'));
        $this->travelTo($this->time('13:59'));

        Livewire::test(ManageReservations::class)
            ->assertSee('Check-in opens Nov 13, 2:00 PM')
            ->assertTableActionVisible('check_in', $this->booking)
            ->assertTableActionDisabled('check_in', $this->booking)
            ->assertTableActionVisible('late_call', $this->booking)
            ->assertTableActionDisabled('late_call', $this->booking)
            ->assertTableActionVisible('release_no_show', $this->booking)
            ->assertTableActionDisabled('release_no_show', $this->booking);

        $this->travelTo($this->time('14:15'));

        Livewire::test(ManageReservations::class)
            ->assertTableActionEnabled('check_in', $this->booking)
            ->assertTableActionEnabled('late_call', $this->booking)
            ->assertTableActionDisabled('release_no_show', $this->booking);
    }

    public function test_unanswered_call_requires_five_minutes_and_an_attested_walk_in(): void
    {
        $this->travelTo($this->time('14:20'));
        $this->actingAs($this->staff);
        $this->service->recordLateCall($this->booking, 'no_answer', true, $this->staff);
        $this->assertSame('14:25:00', $this->booking->fresh()->replyDeadline()->toTimeString());

        $this->travelTo($this->time('14:24:59'));
        $this->assertBlocked(fn () => $this->service->releaseNoShow($this->booking, true, $this->staff));
        $this->assertBlocked(fn () => $this->service->recordLateCall($this->booking, 'no_answer', true, $this->staff));

        $this->travelTo($this->time('14:25'));
        $this->assertBlocked(fn () => $this->service->releaseNoShow($this->booking, false, $this->staff));
        $this->service->releaseNoShow($this->booking, true, $this->staff);
        $this->assertSame('no_show', $this->booking->fresh()->status);
        $this->assertBlocked(fn () => $this->service->recordLateCall($this->booking, 'coming', true, $this->staff));
        $this->assertBlocked(fn () => $this->service->checkIn($this->booking, $this->staff));
        $this->assertDatabaseHas('staff_actions', ['user_id' => $this->staff->id, 'action' => 'reservations.updated', 'subject_id' => $this->booking->id]);

        $this->post('/booking/lookup', ['reference' => $this->booking->reference])->assertRedirect(route('booking.status'));
        $this->get('/booking/status')->assertOk()->assertSee('Your table was released.');
        $this->assertSame('walk_in', $this->walkIn(60)->source);
    }

    public function test_phone_confirmation_starts_a_full_timer_and_check_in_preserves_it(): void
    {
        $this->travelTo($this->time('14:20'));
        $this->service->recordLateCall($this->booking, 'coming', true, $this->staff);
        $this->assertSame('15:20:00', $this->booking->fresh()->session_ends_at->toTimeString());
        $this->assertTrue($this->service->available($this->time('15:00')->toDateTimeString(), 15)->isEmpty());
        $this->assertBlocked(fn () => $this->block('15:00', '15:15'));

        $this->travelTo($this->time('14:25'));
        $this->service->checkIn($this->booking, $this->staff);
        $record = $this->booking->fresh();
        $this->assertSame('14:20:00', $record->session_starts_at->toTimeString());
        $this->assertSame('14:25:00', $record->checked_in_at->toTimeString());
        $this->assertSame(20000, $record->session_total_minor);
        $this->assertBlocked(fn () => $this->service->releaseNoShow($this->booking, true, $this->staff));
        $this->assertBlocked(fn () => $this->service->checkIn($this->booking, $this->staff));
    }

    public function test_dashboard_requires_agreement_to_a_shortened_session_and_guest_sees_the_new_quote(): void
    {
        $this->confirmedBooking($this->time('15:00'));
        $this->travelTo($this->time('14:20'));
        $this->actingAs($this->staff);
        Filament::setCurrentPanel(Filament::getPanel('admin'));

        Livewire::test(ManageReservations::class)
            ->mountTableAction('late_call', $this->booking)
            ->assertSet('mountedActions.0.name', 'late_call')
            ->assertActionDataSet(['offer_summary' => 'Up to 40 minutes · PHP 150.00 · Ends Nov 13, 3:00 PM'])
            ->setActionData(['outcome' => 'coming', 'walk_in_waiting' => true])
            ->callMountedAction()->assertHasActionErrors(['offer_accepted'])
            ->setActionData(['offer_accepted' => true])
            ->callMountedAction()->assertHasNoActionErrors();

        $record = $this->booking->fresh();
        $this->assertSame('14:00:00', $record->starts_at->toTimeString());
        $this->assertSame('14:20:00', $record->session_starts_at->toTimeString());
        $this->assertSame('15:00:00', $record->session_ends_at->toTimeString());
        $this->assertSame(20000, $record->quoted_total_minor);
        $this->assertSame(15000, $record->session_total_minor);

        $this->post('/booking/lookup', ['reference' => $record->reference])->assertRedirect(route('booking.status'));
        $this->get('/booking/status')->assertOk()->assertSee('Session ends')->assertSee('PHP 150.00');

        $this->travelTo($this->time('14:24:59'));
        $this->assertBlocked(fn () => $this->service->releaseNoShow($this->booking, true, $this->staff));
        $this->travelTo($this->time('14:25'));
        Livewire::test(ManageReservations::class)
            ->callTableAction('release_no_show', $record, ['walk_in_waiting' => true])
            ->assertHasNoActionErrors();
        $this->assertSame('no_show', $record->fresh()->status);
        $this->assertBlocked(fn () => $this->walkIn(45));
        $this->assertSame('walk_in', $this->walkIn(30)->source);
    }

    public function test_check_in_rejects_outdated_or_too_short_offers_and_starts_a_valid_timer(): void
    {
        $this->travelTo($this->time('14:20'));
        $this->block('15:05', '16:00');
        $offer = $this->service->sessionOffer($this->booking, $this->time('14:20'));
        $this->assertSame(15000, $offer['total_minor']);

        $this->travelTo($this->time('14:36'));
        $this->assertBlocked(fn () => $this->service->checkIn($this->booking, $this->staff, $offer['key']));
        $revised = $this->service->sessionOffer($this->booking, $this->time('14:36'));
        $this->assertSame(10000, $revised['total_minor']);

        $this->travelTo($this->time('14:51'));
        $this->assertBlocked(fn () => $this->service->checkIn($this->booking, $this->staff, $revised['key']));
        $this->assertNull($this->booking->fresh()->session_starts_at);

        $this->travelTo($this->time('14:36'));
        $this->service->checkIn($this->booking, $this->staff, $revised['key']);
        $this->assertSame('14:36:00', $this->booking->fresh()->session_starts_at->toTimeString());
        $this->assertSame('15:05:00', $this->booking->fresh()->session_ends_at->toTimeString());
    }

    public function test_late_check_in_near_closing_caps_the_timer_at_the_overnight_close(): void
    {
        $this->travelTo($this->time('01:20')->addDay());
        $record = $this->confirmedBooking($this->time('01:00')->addDay());
        $offer = $this->service->sessionOffer($record, CarbonImmutable::now());
        $this->assertSame(15000, $offer['total_minor']);
        $this->service->checkIn($record, $this->staff, $offer['key']);
        $this->assertSame('02:00:00', $record->fresh()->session_ends_at->toTimeString());
    }

    private function time(string $time): CarbonImmutable
    {
        return CarbonImmutable::parse('2026-11-13 '.$time, config('app.timezone'));
    }

    private function confirmedBooking(CarbonImmutable $start): Reservation
    {
        return Reservation::factory()->for($this->table)->create([
            'reference' => Str::random(32), 'starts_at' => $start, 'ends_at' => $start->addHour(),
            'status' => 'confirmed', 'source' => 'website', 'hold_expires_at' => null,
            'quoted_hourly_rate_minor' => 20000, 'quoted_total_minor' => 20000,
        ]);
    }

    private function block(string $start, string $end): void
    {
        $this->service->block([
            'type' => 'maintenance', 'reason' => 'Repair',
            'starts_at' => $this->time($start)->toDateTimeString(), 'ends_at' => $this->time($end)->toDateTimeString(),
        ], $this->staff);
    }

    private function walkIn(int $minutes): Reservation
    {
        return $this->service->create([
            'billiard_table_id' => $this->table->id, 'starts_at' => now()->toDateTimeString(), 'minutes' => $minutes,
            'customer_name' => 'Walk-in Guest', 'phone' => '09171234567', 'hourly_rate' => 20000, 'submission_key' => Str::uuid()->toString(),
        ], $this->staff);
    }

    private function assertBlocked(\Closure $attempt): void
    {
        try {
            $attempt();
            $this->fail('Expected this booking action to be rejected.');
        } catch (ValidationException $exception) {
            $this->assertArrayHasKey('booking', $exception->errors());
        }
    }
}
