<?php

namespace Tests\Feature;

use App\Models\BusinessSetting;
use App\Models\Tournament;
use App\Models\TournamentRegistration;
use App\Models\User;
use App\Services\TournamentService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Tests\TestCase;

class TournamentFlowTest extends TestCase
{
    use RefreshDatabase;

    private function data(): array
    {
        return ['full_name' => 'Test Player', 'phone' => '09171234567', 'submission_key' => (string) Str::uuid(), 'quoted_fee_minor' => 5000];
    }

    private function event(): Tournament
    {
        BusinessSetting::where('key', 'gcash_recipient_name')->firstOrFail()->update(['value' => 'Test CueLab', 'is_verified' => true]);
        BusinessSetting::where('key', 'gcash_recipient_number')->firstOrFail()->update(['value' => '09170000000', 'is_verified' => true]);

        return Tournament::factory()->create(['status' => 'published', 'registration_deadline' => now()->addDays(2), 'entry_fee_minor' => 5000, 'capacity' => 1]);
    }

    public function test_registration_flow_is_private_and_idempotent(): void
    {
        $event = $this->event();
        $this->get(route('tournament.show', $event))->assertOk();
        $data = [...$this->data(), 'submission_key' => session('tournament_draft.key'), 'consent' => 1];
        $this->post(route('tournament.register', $event), $data)->assertRedirect(route('tournament.status'));
        $this->post(route('tournament.register', $event), $data)->assertRedirect(route('tournament.status'));
        $this->assertDatabaseCount('tournament_registrations', 1);
        $this->get(route('tournament.status'))->assertOk()->assertDontSee('Test Player')->assertDontSee('09171234567');
    }

    public function test_last_seat_cannot_be_oversold(): void
    {
        $event = $this->event();
        $service = app(TournamentService::class);
        $service->register($event, $this->data());
        $this->expectException(ValidationException::class);
        $service->register($event, [...$this->data(), 'phone' => '09179999999']);
    }

    public function test_fee_changes_require_review(): void
    {
        $event = $this->event();
        $event->update(['entry_fee_minor' => 6000]);
        $this->expectException(ValidationException::class);
        app(TournamentService::class)->register($event, $this->data());
    }

    public function test_payment_and_cancellation_preserve_payment_history(): void
    {
        $service = app(TournamentService::class);
        $record = TournamentRegistration::factory()->for($this->event())->create([
            'status' => 'paid', 'payment_method' => 'cash', 'paid_at' => now(), 'quoted_fee_minor' => 5000,
        ]);
        $staff = User::factory()->create(['is_staff' => true]);
        $this->actingAs($staff);
        $service->cancelRegistration($record, $staff);
        $this->assertSame('cancelled', $record->fresh()->status);
        $this->assertSame('cash', $record->fresh()->payment_method);
        $this->assertNotNull($record->fresh()->paid_at);
        $this->assertDatabaseHas('staff_actions', ['action' => 'tournament_registrations.updated']);
    }

    public function test_publication_blocks_tables_and_cancellation_releases_them(): void
    {
        $staff = User::factory()->create(['is_staff' => true]);
        $service = app(TournamentService::class);
        $data = ['title' => 'Test event', 'rules' => 'Test rules', 'starts_at' => now()->addWeek()->toDateTimeString(), 'ends_at' => now()->addWeek()->addHours(3)->toDateTimeString(), 'registration_deadline' => now()->addDays(2)->toDateTimeString(), 'capacity' => 16, 'entry_fee_minor' => 5000, 'status' => 'published'];
        $event = $service->save($data, $staff);
        $this->assertDatabaseHas('availability_blocks', ['tournament_id' => $event->id]);
        $service->save([...$data, 'status' => 'cancelled'], $staff, $event);
        $this->assertDatabaseCount('availability_blocks', 0);
    }

    public function test_admin_pages_require_staff(): void
    {
        $this->actingAs(User::factory()->create())->get('/admin/tournaments')->assertForbidden();
        $this->actingAs(User::factory()->create(['is_staff' => true]));
        $this->get('/admin/tournaments')->assertOk();
        $this->get('/admin/tournament-registrations')->assertOk();
    }

    public function test_drafts_are_private_and_deadlines_are_enforced(): void
    {
        $event = $this->event();
        $event->update(['status' => 'draft']);
        $this->get(route('tournament.show', $event))->assertNotFound();
        $event->update(['status' => 'published', 'registration_deadline' => now()->subMinute()]);
        $this->expectException(ValidationException::class);
        app(TournamentService::class)->register($event, $this->data());
    }
}
