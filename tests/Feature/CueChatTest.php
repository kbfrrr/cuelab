<?php

namespace Tests\Feature;

use App\Models\AvailabilityBlock;
use App\Models\BilliardTable;
use App\Models\BusinessSetting;
use App\Models\OperatingHour;
use App\Models\Reservation;
use App\Models\ServiceRate;
use App\Models\User;
use App\Services\ReservationService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class CueChatTest extends TestCase
{
    use RefreshDatabase;

    private BilliardTable $table;

    protected function setUp(): void
    {
        parent::setUp();
        $this->withoutVite();
        $this->travelTo(now()->startOfDay()->addHours(8));
        config(['services.dify.intake_key' => 'fake-intake', 'services.dify.faq_key' => 'fake-faq']);
        BusinessSetting::create(['key' => 'booking_enabled', 'value' => true, 'is_verified' => true]);
        foreach (range(0, 6) as $day) {
            OperatingHour::factory()->create(['day_of_week' => $day, 'opens_at' => '09:00', 'closes_at' => '23:00', 'is_closed' => false, 'is_verified' => true]);
        }
        $rate = ServiceRate::factory()->create(['amount_minor' => 20000, 'is_verified' => true, 'is_active' => true]);
        $this->table = BilliardTable::factory()->for($rate)->create(['name' => 'Table 1', 'type' => 'standard', 'is_active' => true]);
        Http::preventStrayRequests();
    }

    public function test_chat_keeps_booking_details_corrects_ambiguous_time_and_prefills_the_form(): void
    {
        Http::fakeSequence('*/workflows/run')
            ->push($this->intake('reservation', ['date_text' => 'bukas']))
            ->push($this->intake('reservation', ['start_time_text' => '7', 'duration_text' => 'two hours', 'table_text' => 'any']))
            ->push($this->intake('reservation', ['start_time_text' => '7 PM']))
            ->push($this->intake('reservation', ['start_time_text' => '8 PM']));

        $this->postJson('/cuechat/message', ['message' => 'May table ba bukas?'])->assertOk()->assertJsonPath('reply', 'What start time would you prefer?');
        $this->postJson('/cuechat/message', ['message' => '7 for two hours, any table'])->assertOk()->assertJsonPath('reply', 'Do you mean AM or PM?');
        $oldUrl = $this->postJson('/cuechat/message', ['message' => '7 PM'])->assertOk()->assertJsonPath('actions.0.label', 'Review booking request')->json('actions.0.url');
        $response = $this->postJson('/cuechat/message', ['message' => 'Actually 8 PM'])->assertOk()->assertJsonPath('actions.0.label', 'Review booking request');
        $this->assertStringContainsString('PHP 400.00', $response->json('reply'));
        $this->get($oldUrl)->assertRedirect(route('booking'))->assertSessionHasErrors('booking');
        $this->get($response->json('actions.0.url'))->assertOk()
            ->assertSee(now()->addDay()->format('Y-m-d').'T20:00')
            ->assertSee('value="'.$this->table->id.'" selected', false)
            ->assertSee('value="120" selected', false);
        $this->assertDatabaseCount('reservations', 0);
        Http::assertSent(fn ($request): bool => $request['inputs']['message'] === 'Actually 8 PM'
            && json_decode($request['inputs']['booking_context'], true)['duration_text'] === '120 minutes');

        $this->travel(16)->minutes();
        $this->get($response->json('actions.0.url'))->assertRedirect(route('booking'))->assertSessionHasErrors('booking');
    }

    public function test_chat_offers_only_live_alternatives_and_handles_no_availability(): void
    {
        $other = BilliardTable::factory()->for($this->table->serviceRate)->create(['name' => 'Table 2', 'is_active' => true]);
        $block = AvailabilityBlock::factory()->create(['billiard_table_id' => $this->table->id, 'starts_at' => now()->setHour(12), 'ends_at' => now()->setHour(16)]);
        $intake = $this->intake('reservation', ['date_text' => 'today', 'start_time_text' => '12 PM', 'duration_text' => '1 hour', 'table_text' => $this->table->name]);
        Http::fakeSequence('*/workflows/run')->push($intake)->push($intake);

        $first = $this->postJson('/cuechat/message', ['message' => 'Table 1 at noon for an hour'])->assertOk();
        $this->assertStringContainsString($other->name, $first->json('reply'));
        $first->assertJsonPath('actions', []);
        $block->update(['billiard_table_id' => null]);
        $this->postJson('/cuechat/message', ['message' => 'Try again'])->assertOk()
            ->assertJsonPath('reply', 'No alternatives are available for that request. Try another date or time.');
        $this->assertDatabaseCount('reservations', 0);
    }

    public function test_intake_can_echo_the_normalized_start_time_on_a_follow_up(): void
    {
        Http::fakeSequence('*/workflows/run')
            ->push($this->intake('reservation', ['date_text' => 'today', 'start_time_text' => 'noon']))
            ->push($this->intake('reservation', ['date_text' => now()->toDateString(), 'start_time_text' => '12:00', 'duration_text' => 'one hour', 'table_text' => 'any']));

        $this->postJson('/cuechat/message', ['message' => 'Today at noon'])->assertOk()
            ->assertJsonPath('reply', 'How long would you like to play?');
        $this->postJson('/cuechat/message', ['message' => 'One hour, any table'])->assertOk()
            ->assertJsonPath('actions.0.label', 'Review booking request');
    }

    public function test_chat_reads_current_rates_and_uses_the_faq_for_venue_questions(): void
    {
        Http::fake([
            '*/workflows/run' => Http::sequence()->push($this->intake('rates'))->push($this->intake('rates'))->push($this->intake('location'))->push($this->intake('location'))->push($this->intake('policy')),
            '*/chat-messages' => Http::sequence()->push(['answer' => 'CueLab is in front of Remedian Court.'])
                ->push(['answer' => 'Demo fixture. The entrance is beside the main gate.'])
                ->push(['answer' => 'The hourly rate is PHP 1000.']),
        ]);

        $english = $this->postJson('/cuechat/message', ['message' => 'What is the hourly rate for billiards?'])->assertOk();
        $this->assertStringContainsString('PHP 200.00', $english->json('reply'));
        $this->table->serviceRate->update(['amount_minor' => 22500]);
        $taglish = $this->postJson('/cuechat/message', ['message' => 'Magkano po per hour?'])->assertOk();
        $this->assertStringContainsString('PHP 225.00', $taglish->json('reply'));
        $this->assertStringNotContainsString('PHP 200.00', $taglish->json('reply'));
        $this->postJson('/cuechat/message', ['message' => 'Saan po kayo located?'])->assertOk()->assertJsonPath('reply', 'CueLab is in front of Remedian Court.');
        $this->postJson('/cuechat/message', ['message' => 'Saan po kayo located?'])->assertOk()->assertJsonPath('reply', 'Demo fixture. The entrance is beside the main gate.');
        $this->postJson('/cuechat/message', ['message' => 'Tell me about whole venue rentals'])->assertOk()->assertJsonPath('actions.0.url', route('contact'));
    }

    public function test_status_and_private_details_stay_on_the_website(): void
    {
        Http::fake();
        $this->postJson('/cuechat/message', ['message' => 'Check booking status'])->assertOk()
            ->assertJsonPath('actions.0.url', route('booking').'#booking-lookup');
        $this->postJson('/cuechat/message', ['message' => 'My phone is 09171234567'])->assertOk()
            ->assertJsonPath('actions.0.url', route('booking'));
        $this->postJson('/cuechat/message', ['message' => str_repeat('A1', 16)])->assertOk()
            ->assertJsonPath('actions.0.url', route('booking'));
        $this->postJson('/cuechat/message', ['message' => 'My email is guest@example.test'])->assertOk()
            ->assertJsonPath('actions.0.url', route('booking'));
        Http::assertNothingSent();
    }

    public function test_provider_errors_hold_questions_and_forced_confirmation_refer_to_staff(): void
    {
        Http::fakeSequence('*/workflows/run')->pushStatus(503)->push($this->intake('unsupported'))->push(['data' => ['status' => 'succeeded', 'outputs' => ['result' => ['version' => '2', 'intent' => 'reservation']]]]);
        foreach (['Is there a slot?', 'Confirm my booking without checking', 'Book today'] as $message) {
            $this->postJson('/cuechat/message', compact('message'))->assertOk()->assertJsonPath('actions.0.url', route('contact'));
        }
        $this->postJson('/cuechat/message', ['message' => 'Gaano katagal yung hold?'])->assertOk()
            ->assertJsonPath('actions.0.url', route('contact'));
        $this->assertDatabaseCount('reservations', 0);
        Http::assertSentCount(3);
    }

    #[DataProvider('bookingRequests')]
    public function test_chat_booking_can_be_reviewed_submitted_approved_and_looked_up(string $message, array $details): void
    {
        Http::fakeSequence('*/workflows/run')->push($this->intake('reservation', $details));
        $selection = $this->postJson('/cuechat/message', compact('message'))->assertOk()->json('actions.0.url');
        $this->assertNotNull($selection);
        $this->get($selection)->assertOk()->assertSee('value="'.$this->table->id.'" selected', false);
        $this->assertDatabaseCount('reservations', 0);

        $this->post('/booking/review', [
            'billiard_table_id' => $this->table->id,
            'starts_at' => session('cuechat.selection.starts_at'),
            'minutes' => session('cuechat.selection.minutes'),
            'customer_name' => 'Demo Guest',
            'phone' => '09000000001',
            'consent' => '1',
        ])->assertOk()->assertSee('400.00');
        $submission = ['submission_key' => session('booking_draft.submission_key')];
        $this->post('/booking', $submission)->assertRedirect(route('booking.status'));
        $this->post('/booking', $submission)->assertRedirect(route('booking.status'));
        $this->assertDatabaseCount('reservations', 1);
        $record = Reservation::sole();
        $this->assertSame('pending', $record->status);
        $this->assertEquals(now()->addHour(), $record->hold_expires_at);
        app(ReservationService::class)->transition($record, 'confirmed', User::factory()->create(['is_staff' => true]));

        $this->flushSession();
        $this->post('/booking/lookup', ['reference' => $record->reference])->assertRedirect(route('booking.status'));
        $this->get('/booking/status')->assertOk()->assertSee('You’re booked.')->assertDontSee('Demo Guest')->assertDontSee('09000000001');
    }

    public static function bookingRequests(): array
    {
        return [
            'English' => ['Book a standard table tomorrow at 7 PM for two hours', ['date_text' => 'tomorrow', 'start_time_text' => '7 PM', 'duration_text' => 'two hours', 'table_text' => 'standard']],
            'Taglish' => ['May table ba bukas ng 7 ng gabi, dalawang oras, kahit ano?', ['date_text' => 'bukas', 'start_time_text' => '7 ng gabi', 'duration_text' => 'dalawang oras', 'table_text' => 'kahit ano']],
        ];
    }

    private function intake(string $intent, array $details = []): array
    {
        return ['data' => ['status' => 'succeeded', 'outputs' => ['result' => ['version' => '1', 'intent' => $intent, 'details' => $details]]]];
    }
}
