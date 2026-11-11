<?php

namespace App\Services;

use App\Models\AvailabilityBlock;
use App\Models\Tournament;
use App\Models\TournamentRegistration;
use App\Models\User;
use Carbon\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

class TournamentService
{
    public function register(Tournament $event, array $data): TournamentRegistration
    {
        $data = Validator::make($data, ['full_name' => 'required|string|max:150', 'phone' => ['required', 'regex:/^\+?[0-9 ()-]{7,32}$/'], 'submission_key' => 'required|uuid', 'quoted_fee_minor' => 'required|integer|min:0'])->validate();
        $data['phone'] = preg_replace('/\D/', '', $data['phone']);
        if (strlen($data['phone']) < 7) {
            $this->fail('Enter a valid phone number.');
        }

        return DB::transaction(function () use ($event, $data) {
            $event = Tournament::findOrFail($event->id);
            $existing = TournamentRegistration::where('submission_key', $data['submission_key'])->first();
            if ($existing) {
                if ($existing->tournament_id !== $event->id || $existing->phone !== $data['phone'] || $existing->full_name !== $data['full_name']) {
                    $this->fail('Submission already used. Start again.');
                }

                return $existing;
            }
            if ($event->status !== 'published' || $event->starts_at <= now() || ! $event->registration_deadline || $event->registration_deadline <= now()) {
                $this->fail('Registration is closed.');
            }
            if ($event->entry_fee_minor !== (int) $data['quoted_fee_minor']) {
                $this->fail('The entry fee changed. Refresh and review the new fee.');
            }
            if ($event->registrations()->where('status', '!=', 'cancelled')->where('phone', $data['phone'])->exists()) {
                $this->fail('This phone number is already registered. Contact staff for help.');
            }
            if ($event->registrations()->where('status', '!=', 'cancelled')->count() >= $event->capacity) {
                $this->fail('This tournament is full.');
            }

            return $event->registrations()->create(['full_name' => $data['full_name'], 'phone' => $data['phone'], 'submission_key' => $data['submission_key'], 'reference' => Str::random(32), 'quoted_fee_minor' => $event->entry_fee_minor, 'currency' => $event->currency, 'status' => 'unpaid']);
        }, 3);
    }

    public function save(array $data, User $staff, ?Tournament $event = null): Tournament
    {
        abort_unless($staff->is_staff, 403);
        $data = Validator::make($data, ['title' => 'required|string|max:150', 'description' => 'nullable|string|max:5000', 'rules' => 'required|string|max:5000', 'starts_at' => 'required|date', 'ends_at' => 'required|date|after:starts_at', 'registration_deadline' => 'required|date|before_or_equal:starts_at', 'capacity' => 'required|integer|min:1|max:1000', 'entry_fee_minor' => 'required|integer|min:0|max:10000000', 'status' => 'required|in:draft,published,closed,cancelled'])->validate();

        return DB::transaction(function () use ($data, $staff, $event) {
            app(ReservationService::class)->lockScheduleForWrite();
            $event = $event ? Tournament::findOrFail($event->id) : new Tournament;
            if ($event->exists && $event->registrations()->where('status', '!=', 'cancelled')->count() > $data['capacity']) {
                $this->fail('Capacity cannot be smaller than existing registrations.');
            }
            if ($event->exists && $event->registrations()->exists() && in_array($data['status'], ['draft', 'published', 'closed']) && ($event->starts_at->ne(Carbon::parse($data['starts_at'])) || $event->ends_at->ne(Carbon::parse($data['ends_at'])))) {
                $this->fail('Registered events cannot be rescheduled. Cancel and create a replacement.');
            }
            if ($event->exists && $data['status'] === 'draft' && $event->registrations()->exists()) {
                $this->fail('An event with registrations cannot return to draft. Close or cancel it instead.');
            }
            $event->fill([...$data, 'currency' => 'PHP'])->save();
            foreach ($event->availabilityBlocks()->get() as $block) {
                $block->delete();
            }
            if (in_array($event->status, ['published', 'closed'])) {
                if (AvailabilityBlock::where('starts_at', '<', $event->ends_at)->where('ends_at', '>', $event->starts_at)->exists()) {
                    $this->fail('This event overlaps another closure, maintenance period, or tournament.');
                }
                $block = app(ReservationService::class)->block(['type' => 'tournament', 'reason' => $event->title, 'starts_at' => $event->starts_at->toDateTimeString(), 'ends_at' => $event->ends_at->toDateTimeString()], $staff);
                $block->update(['tournament_id' => $event->id]);
            }

            return $event;
        }, 3);
    }

    public function updateRegistration(TournamentRegistration $registration, string $action, User $staff, array $payment = []): void
    {
        abort_unless($staff->is_staff, 403);
        DB::transaction(function () use ($registration, $action, $staff, $payment) {
            $registration = TournamentRegistration::findOrFail($registration->id);
            if ($registration->status === 'cancelled') {
                $this->fail('Registration already cancelled.');
            }
            if ($action === 'paid') {
                if ($registration->status !== 'unpaid' || $registration->tournament->status === 'cancelled') {
                    $this->fail('This payment cannot be recorded.');
                }
                $payment = Validator::make($payment, ['payment_method' => 'required|in:cash,gcash', 'payment_reference' => 'nullable|string|max:150|required_if:payment_method,gcash'])->validate();
                $registration->update([...$payment, 'status' => 'paid', 'paid_at' => now(), 'payment_verified_by' => $staff->id]);
            } elseif ($action === 'cancelled') {
                $registration->update(['status' => 'cancelled']);
            } else {
                $this->fail('Unsupported action.');
            }
        }, 3);
    }

    private function fail(string $message): never
    {
        throw ValidationException::withMessages(['tournament' => $message]);
    }
}
