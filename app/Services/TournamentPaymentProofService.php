<?php

namespace App\Services;

use App\Models\BusinessSetting;
use App\Models\StaffAction;
use App\Models\Tournament;
use App\Models\TournamentRegistration;
use App\Models\User;
use Illuminate\Database\Query\Builder;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

class TournamentPaymentProofService
{
    public const RECIPIENT_NAME = 'gcash_recipient_name';

    public const RECIPIENT_NUMBER = 'gcash_recipient_number';

    public const MOBILE_PATTERN = '/\A(?:09[0-9]{9}|\+639[0-9]{9})\z/';

    /** @return array{name: string, number: string}|null */
    public function recipient(): ?array
    {
        $settings = BusinessSetting::query()
            ->whereIn('key', [self::RECIPIENT_NAME, self::RECIPIENT_NUMBER])
            ->where('is_verified', true)
            ->get()->keyBy('key');

        $name = $settings->get(self::RECIPIENT_NAME)?->value;
        $number = $settings->get(self::RECIPIENT_NUMBER)?->value;

        if (! is_string($name) || trim($name) === '' || ! is_string($number) || ! preg_match(self::MOBILE_PATTERN, trim($number))) {
            return null;
        }

        return ['name' => trim($name), 'number' => trim($number)];
    }

    public function proofs(): Builder
    {
        return DB::table('tournament_payment_proofs');
    }

    public function currentProof(TournamentRegistration $registration): ?object
    {
        return $this->proofs()->where('tournament_registration_id', $registration->id)
            ->first(['version', 'status', 'transaction_reference', 'rejection_reason', 'submitted_at', 'reviewed_at']);
    }

    public function canSubmit(TournamentRegistration $registration, ?object $proof): bool
    {
        return $registration->status === 'unpaid'
            && $registration->quoted_fee_minor > 0
            && in_array($registration->tournament->status, ['published', 'closed'], true)
            && $registration->tournament->ends_at->isFuture()
            && ($proof === null || $proof->status === 'rejected');
    }

    public function submit(TournamentRegistration $registration, string $reference, UploadedFile $image): void
    {
        $reference = strtoupper(str_replace([' ', '-'], '', $reference));
        if (! preg_match('/\A[A-Z0-9]{1,40}\z/', $reference)) {
            throw ValidationException::withMessages(['transaction_reference' => 'Enter a valid GCash transaction reference.']);
        }

        $contents = $image->get();
        $mimeType = is_string($contents) ? (@getimagesizefromstring($contents)['mime'] ?? null) : null;
        if (! in_array($mimeType, ['image/jpeg', 'image/png'], true)) {
            throw ValidationException::withMessages(['screenshot' => 'Upload a valid JPEG or PNG screenshot.']);
        }

        DB::transaction(function () use ($registration, $reference, $contents, $mimeType): void {
            $tournament = Tournament::query()->lockForUpdate()->findOrFail($registration->tournament_id);
            $record = TournamentRegistration::query()->lockForUpdate()->findOrFail($registration->id);
            $record->setRelation('tournament', $tournament);
            $proof = $this->currentProof($record);

            if (! $this->canSubmit($record, $proof)) {
                throw ValidationException::withMessages(['screenshot' => 'This registration cannot accept payment proof right now. Refresh its status and try again.']);
            }

            $now = now();
            $data = [
                'version' => (string) Str::uuid(),
                'transaction_reference' => $reference,
                'status' => 'pending',
                'mime_type' => $mimeType,
                'image_base64' => base64_encode($contents),
                'submitted_at' => $now,
                'reviewed_at' => null,
                'reviewed_by' => null,
                'rejection_reason' => null,
                'updated_at' => $now,
            ];

            if ($proof === null) {
                $this->proofs()->insert([...$data, 'tournament_registration_id' => $record->id, 'created_at' => $now]);
            } else {
                $this->proofs()->where('tournament_registration_id', $record->id)->update($data);
            }
        }, 3);
    }

    public function review(TournamentRegistration $registration, User $staff, string $version, ?string $rejectionReason = null): void
    {
        abort_unless($staff->is_staff, 403);
        if ($rejectionReason !== null) {
            $rejectionReason = trim($rejectionReason);
            if ($rejectionReason === '' || mb_strlen($rejectionReason) > 500) {
                throw ValidationException::withMessages(['rejection_reason' => 'Enter a reason of up to 500 characters.']);
            }
        }

        try {
            DB::transaction(function () use ($registration, $staff, $version, $rejectionReason): void {
                $tournament = Tournament::query()->lockForUpdate()->findOrFail($registration->tournament_id);
                $record = TournamentRegistration::query()->lockForUpdate()->findOrFail($registration->id);
                $proof = $this->proofs()->where('tournament_registration_id', $record->id)
                    ->where('version', $version)->where('status', 'pending');

                if ($record->status !== 'unpaid' || $record->quoted_fee_minor <= 0 || $tournament->status === 'cancelled') {
                    throw ValidationException::withMessages(['decision' => 'This registration no longer accepts payment review.']);
                }

                $submission = $proof->select('transaction_reference')->selectRaw('image_base64 IS NOT NULL AS has_image')->lockForUpdate()->first();
                if ($submission === null) {
                    throw ValidationException::withMessages(['decision' => 'This proof is no longer available for review. Reopen the registration to see its current state.']);
                }

                $approved = $rejectionReason === null;
                if ($approved && (! $submission->has_image || $tournament->ends_at->lte(now()->subDays(30)))) {
                    throw ValidationException::withMessages(['decision' => 'This screenshot has expired. Reject the proof and explain how the player can contact staff.']);
                }

                $reference = $submission->transaction_reference;
                if ($approved && TournamentRegistration::query()->where('payment_method', 'gcash')
                    ->whereRaw("upper(replace(replace(trim(payment_reference), ' ', ''), '-', '')) = ?", [$reference])->exists()) {
                    throw $this->duplicateReference();
                }

                $now = now();
                $proof->update([
                    'status' => $approved ? 'approved' : 'rejected',
                    'approved_reference' => $approved ? $reference : null,
                    'reviewed_by' => $staff->id,
                    'reviewed_at' => $now,
                    'rejection_reason' => $rejectionReason,
                    'updated_at' => $now,
                ]);

                if ($approved) {
                    $record->update([
                        'status' => 'paid',
                        'payment_method' => 'gcash',
                        'payment_reference' => $reference,
                        'paid_at' => $now,
                        'payment_verified_by' => $staff->id,
                    ]);
                }

                StaffAction::create([
                    'user_id' => $staff->id,
                    'action' => $approved ? 'tournament_payment_proof.approved' : 'tournament_payment_proof.rejected',
                    'subject_type' => $record->getMorphClass(),
                    'subject_id' => $record->id,
                    'metadata' => ['status' => $approved ? 'approved' : 'rejected'],
                ]);
            }, 3);
        } catch (UniqueConstraintViolationException $exception) {
            if ($rejectionReason === null && $this->proofs()->where('approved_reference', function (Builder $query) use ($registration, $version): void {
                $query->select('transaction_reference')->from('tournament_payment_proofs')
                    ->where('tournament_registration_id', $registration->id)->where('version', $version);
            })->exists()) {
                throw $this->duplicateReference();
            }

            throw $exception;
        }
    }

    public function purgeExpiredImages(): int
    {
        return $this->proofs()->whereNotNull('image_base64')
            ->whereIn('tournament_registration_id', TournamentRegistration::query()->select('tournament_registrations.id')
                ->join('tournaments', 'tournaments.id', '=', 'tournament_registrations.tournament_id')
                ->where('tournaments.ends_at', '<=', now()->subDays(30)))
            ->update(['image_base64' => null]);
    }

    private function duplicateReference(): ValidationException
    {
        return ValidationException::withMessages(['decision' => 'This GCash transaction reference has already been used to verify a payment.']);
    }
}
