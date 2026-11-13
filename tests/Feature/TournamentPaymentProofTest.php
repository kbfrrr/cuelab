<?php

namespace Tests\Feature;

use App\Filament\Resources\TournamentRegistrations\Pages\ManageTournamentRegistrations;
use App\Models\BusinessSetting;
use App\Models\Tournament;
use App\Models\TournamentRegistration;
use App\Models\User;
use App\Services\TournamentPaymentProofService;
use App\Services\TournamentService;
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Testing\TestResponse;
use Illuminate\Validation\ValidationException;
use Livewire\Livewire;
use Tests\TestCase;

class TournamentPaymentProofTest extends TestCase
{
    use RefreshDatabase;

    private const PNG = 'iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAQAAAC1HAwCAAAAC0lEQVR42mP8/x8AAwMCAO+jE1sAAAAASUVORK5CYII=';

    private function screenshot(): UploadedFile
    {
        return UploadedFile::fake()->createWithContent('receipt.png', base64_decode(self::PNG));
    }

    private function registration(array $attributes = []): TournamentRegistration
    {
        return TournamentRegistration::factory()
            ->for(Tournament::factory()->state(['status' => 'published', 'capacity' => 1, 'registration_deadline' => now()->addDay(), 'entry_fee_minor' => 50000]))
            ->create(['status' => 'unpaid', 'reference' => Str::random(32), 'quoted_fee_minor' => 50000, ...$attributes]);
    }

    private function upload(TournamentRegistration $registration, array $data = []): TestResponse
    {
        return $this->post(route('tournament.proof.store'), [
            'registration_id' => $registration->id,
            'transaction_reference' => '1234-5678 9012',
            'screenshot' => $this->screenshot(),
            ...$data,
        ]);
    }

    private function reviewConflict(callable $review, string $message): void
    {
        try {
            $review();
            $this->fail('Expected the payment review to fail.');
        } catch (ValidationException $exception) {
            $this->assertStringContainsString($message, $exception->getMessage());
        }
    }

    public function test_upload_requires_the_matching_private_registration_session_and_a_valid_image(): void
    {
        $registration = $this->registration();
        $other = $this->registration();

        $this->upload($registration)->assertRedirectToRoute('tournaments');
        $this->post(route('tournament.lookup'), ['reference' => $registration->reference])->assertRedirectToRoute('tournament.status');
        $this->get(route('tournament.status'))->assertOk()->assertSee('Awaiting proof')->assertSee('you can still submit proof');

        $this->upload($other)->assertSessionHasErrors('registration_id');
        $this->upload($registration, ['screenshot' => UploadedFile::fake()->create('receipt.pdf', 5, 'application/pdf')])
            ->assertSessionHasErrors('screenshot');
        $this->upload($registration, ['screenshot' => $this->screenshot()->size(2049)])
            ->assertSessionHasErrors('screenshot');
        $this->assertDatabaseCount('tournament_payment_proofs', 0);

        $registration->tournament->update(['status' => 'closed']);
        $this->upload($registration)->assertRedirectToRoute('tournament.status');
        $this->get(route('tournament.status'))->assertOk()->assertSee('Under review')->assertDontSee('Submit proof');
        $this->assertDatabaseHas('tournament_payment_proofs', [
            'tournament_registration_id' => $registration->id, 'transaction_reference' => '123456789012', 'status' => 'pending',
        ]);
        $this->assertDatabaseHas('tournament_registrations', ['id' => $registration->id, 'status' => 'unpaid']);
    }

    public function test_staff_only_screenshot_and_rejection_resubmission_are_bound_to_the_reviewed_version(): void
    {
        $registration = $this->registration();
        $staff = User::factory()->create(['is_staff' => true]);
        $proofs = app(TournamentPaymentProofService::class);
        $this->withSession(['tournament_reference' => $registration->reference]);
        $this->upload($registration)->assertRedirectToRoute('tournament.status');
        $original = $proofs->currentProof($registration);
        $url = route('tournament.proof.show', ['registration' => $registration->id, 'version' => $original->version]);

        $this->get($url)->assertForbidden();
        $this->actingAs(User::factory()->create())->get($url)->assertForbidden();
        $image = $this->actingAs($staff)->get($url)->assertOk()->assertHeader('Content-Type', 'image/png');
        $this->assertStringContainsString('no-store', $image->headers->get('Cache-Control'));
        $this->assertSame(base64_decode(self::PNG), $image->getContent());

        Filament::setCurrentPanel(Filament::getPanel('admin'));
        $waiting = $this->registration();
        Livewire::test(ManageTournamentRegistrations::class)
            ->filterTable('pending_proof')
            ->assertCanSeeTableRecords([$registration])
            ->assertCanNotSeeTableRecords([$waiting])
            ->assertTableActionVisible('review_proof', $registration);
        $review = Livewire::test(ManageTournamentRegistrations::class)
            ->mountTableAction('review_proof', $registration)
            ->assertTableActionDataSet(['version' => $original->version]);
        $reviewHtml = $review->instance()->getMountedAction()->getModalContent()->render();
        $this->assertStringContainsString('123456789012', $reviewHtml);
        $this->assertStringContainsString($url, $reviewHtml);
        $review
            ->setTableActionData(['decision' => 'reject', 'rejection_reason' => 'The amount does not match.'])
            ->callMountedTableAction()
            ->assertHasNoErrors();
        $this->get(route('tournament.status'))->assertOk()->assertSee('Proof rejected')->assertSee('The amount does not match.');

        $this->upload($registration, ['transaction_reference' => '987654321012'])->assertRedirectToRoute('tournament.status');
        $this->assertDatabaseCount('tournament_payment_proofs', 1);
        $this->get($url)->assertNotFound();
        $this->reviewConflict(fn () => $proofs->review($registration, $staff, $original->version), 'no longer available');
        $current = $proofs->currentProof($registration);
        Livewire::test(ManageTournamentRegistrations::class)
            ->mountTableAction('review_proof', $registration)
            ->setTableActionData(['decision' => 'approve'])
            ->callMountedTableAction()
            ->assertHasNoErrors();
        $this->reviewConflict(fn () => $proofs->review($registration, $staff, $current->version), 'no longer accepts');
        $this->get(route('tournament.status'))->assertOk()->assertSee('Paid');
        $this->assertDatabaseHas('tournament_registrations', [
            'id' => $registration->id, 'status' => 'paid', 'payment_method' => 'gcash', 'payment_reference' => '987654321012',
            'payment_verified_by' => $staff->id,
        ]);
        $this->assertDatabaseHas('staff_actions', ['action' => 'tournament_payment_proof.rejected', 'subject_id' => $registration->id]);
    }

    public function test_cancellation_preserves_approved_references_and_prevents_further_review(): void
    {
        $first = $this->registration();
        $second = $this->registration();
        $staff = User::factory()->create(['is_staff' => true]);
        $proofs = app(TournamentPaymentProofService::class);
        $service = app(TournamentService::class);

        $proofs->submit($first, '1234-5678', $this->screenshot());
        $proofs->review($first, $staff, $proofs->currentProof($first)->version);
        $service->cancelRegistration($first, $staff);

        $proofs->submit($second, '12345678', $this->screenshot());
        $version = $proofs->currentProof($second)->version;
        $this->reviewConflict(fn () => $proofs->review($second, $staff, $version), 'already been used');
        $service->cancelRegistration($second, $staff);
        $this->reviewConflict(fn () => $proofs->review($second, $staff, $version), 'no longer accepts');
        $this->assertSame('12345678', $first->fresh()->payment_reference);
        $this->assertNull($second->fresh()->payment_reference);
    }

    public function test_uploads_close_at_the_event_end_and_pending_proof_can_still_be_reviewed(): void
    {
        $registration = $this->registration();
        $staff = User::factory()->create(['is_staff' => true]);
        $proofs = app(TournamentPaymentProofService::class);
        $proofs->submit($registration, '1234567890', $this->screenshot());
        $registration->tournament->update(['ends_at' => now()->subMinute()]);

        $this->withSession(['tournament_reference' => $registration->reference]);
        $this->upload($registration)->assertSessionHasErrors('screenshot');
        $proofs->review($registration, $staff, $proofs->currentProof($registration)->version, 'The receipt was unreadable.');
        $this->get(route('tournament.status'))->assertOk()
            ->assertSee('Proof rejected')->assertSee('Proof submissions are closed')->assertDontSee('Submit proof');
    }

    public function test_missing_details_pause_paid_registrations_while_free_and_historical_entries_still_work(): void
    {
        $paidEvent = $this->registration()->tournament;
        $freeEvent = Tournament::factory()->create(['status' => 'published', 'registration_deadline' => now()->addDay()]);
        $data = ['full_name' => 'New Player', 'phone' => '09171112222', 'consent' => 1];

        BusinessSetting::where('key', 'gcash_recipient_name')->firstOrFail()->update(['value' => 'Test Recipient', 'is_verified' => true]);
        BusinessSetting::where('key', 'gcash_recipient_number')->firstOrFail()->update(['value' => '09170000000', 'is_verified' => false]);
        $this->get(route('tournament.show', $paidEvent))->assertOk()->assertSee('Registration paused')->assertDontSee('09170000000');
        $this->post(route('tournament.register', $paidEvent), [...$data, 'submission_key' => session('tournament_draft.key')])
            ->assertSessionHasErrors('tournament');

        $this->get(route('tournament.show', $freeEvent))->assertOk();
        $this->post(route('tournament.register', $freeEvent), [...$data, 'submission_key' => session('tournament_draft.key')])
            ->assertRedirectToRoute('tournament.status');
        $this->get(route('tournament.status'))->assertOk()->assertSee('No payment required')->assertDontSee('Submit proof');

        $legacy = $this->registration(['status' => 'paid', 'payment_method' => 'cash', 'paid_at' => now()]);
        $this->withSession(['tournament_reference' => $legacy->reference])->get(route('tournament.status'))->assertOk()->assertSee('Paid')->assertDontSee('Submit proof');
        $this->assertSame('cash', $legacy->fresh()->payment_method);

        BusinessSetting::where('key', 'gcash_recipient_number')->firstOrFail()->update(['is_verified' => true]);
        $existing = $paidEvent->registrations()->firstOrFail();
        $this->withSession(['tournament_reference' => $existing->reference])->get(route('tournament.status'))
            ->assertOk()->assertSee('Test Recipient')->assertSee('09170000000')->assertSee('500.00');
    }

    public function test_pending_and_rejected_proofs_hold_the_seat_until_cancellation(): void
    {
        $registration = $this->registration();
        BusinessSetting::where('key', 'gcash_recipient_name')->firstOrFail()->update(['value' => 'Test CueLab', 'is_verified' => true]);
        BusinessSetting::where('key', 'gcash_recipient_number')->firstOrFail()->update(['value' => '09170000000', 'is_verified' => true]);
        $staff = User::factory()->create(['is_staff' => true]);
        $proofs = app(TournamentPaymentProofService::class);
        $service = app(TournamentService::class);
        $newPlayer = ['full_name' => 'New Player', 'phone' => '09171112222', 'submission_key' => (string) Str::uuid(), 'quoted_fee_minor' => 50000];

        $this->reviewConflict(fn () => $service->register($registration->tournament, $newPlayer), 'full');
        $proofs->submit($registration, '1234567890', $this->screenshot());
        $this->reviewConflict(fn () => $service->register($registration->tournament, $newPlayer), 'full');
        $proofs->review($registration, $staff, $proofs->currentProof($registration)->version, 'Please send the correct screenshot.');
        $this->reviewConflict(fn () => $service->register($registration->tournament, $newPlayer), 'full');
        $service->cancelRegistration($registration, $staff);
        $this->assertSame('unpaid', $service->register($registration->tournament, $newPlayer)->status);
    }

    public function test_cleanup_removes_only_expired_image_data_and_keeps_review_history(): void
    {
        $registration = $this->registration();
        $other = $this->registration();
        $staff = User::factory()->create(['is_staff' => true]);
        $proofs = app(TournamentPaymentProofService::class);
        $proofs->submit($registration, '1234', $this->screenshot());
        $proofs->submit($other, '5678', $this->screenshot());
        $version = $proofs->currentProof($registration)->version;
        $proofs->review($registration, $staff, $version);
        $registration->tournament->update(['ends_at' => now()->subDays(30)]);

        $this->artisan('tournaments:purge-proof-images')->assertSuccessful();
        $this->assertDatabaseHas('tournament_payment_proofs', [
            'tournament_registration_id' => $registration->id, 'image_base64' => null, 'status' => 'approved', 'approved_reference' => '1234',
        ]);
        $this->assertNotNull(DB::table('tournament_payment_proofs')->where('tournament_registration_id', $other->id)->value('image_base64'));
        $this->assertSame('paid', $registration->fresh()->status);
        $this->actingAs($staff)->get(route('tournament.proof.show', ['registration' => $registration->id, 'version' => $version]))->assertNotFound();
    }
}
