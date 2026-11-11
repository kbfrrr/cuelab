<?php

namespace App\Services;

use App\Models\AvailabilityBlock;
use App\Models\BilliardTable;
use App\Models\BusinessSetting;
use App\Models\OperatingHour;
use App\Models\Reservation;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

class ReservationService
{
    public function lockScheduleForWrite(): void
    {
        if (DB::getDriverName() === 'pgsql') {
            DB::select('select pg_advisory_xact_lock(8274, 1)');
        }
    }

    public function findForStatus(?string $reference): ?Reservation
    {
        if ($reference === null || $reference === '') {
            return null;
        }

        $this->expire();

        return Reservation::where('reference', $reference)->first();
    }

    public function enabled(): bool
    {
        return BusinessSetting::where('key', 'booking_enabled')->where('is_verified', true)->first()?->value === true
            && OperatingHour::where('is_verified', true)->whereBetween('day_of_week', [0, 6])->where(fn ($q) => $q->where('is_closed', true)->orWhere(fn ($q) => $q->whereNotNull('opens_at')->whereNotNull('closes_at')))->count() === 7
            && BilliardTable::where('is_active', true)->whereHas('serviceRate', fn ($q) => $q->where('is_verified', true)->where('is_active', true)->where('unit', 'table/hour')->where('currency', 'PHP'))->exists();
    }

    public function window(string $start, int $minutes, bool $walkIn = false): array
    {
        Validator::make(['starts_at' => $start, 'minutes' => $minutes], ['starts_at' => 'required|date', 'minutes' => 'required|integer|min:15|max:480|multiple_of:15'])->validate();
        $from = CarbonImmutable::parse($start, config('app.timezone'))->setSecond(0);
        $to = $from->addMinutes($minutes);
        if ($from->lt($walkIn ? now()->startOfMinute() : now()) || $from->gt(now()->addDays(90))) {
            $this->fail('Choose a future time within the next 90 days.');
        }
        $close = $this->closingTime($from);
        if ($close === null || $to->gt($close)) {
            $this->fail('That time is outside verified opening hours.');
        }

        return [$from, $to];
    }

    private function closingTime(CarbonImmutable $from): ?CarbonImmutable
    {
        $closingTime = null;
        foreach ([$from->startOfDay(), $from->startOfDay()->subDay()] as $day) {
            $hours = OperatingHour::where('day_of_week', $day->dayOfWeek)->where('is_verified', true)->first();
            if (! $hours || $hours->is_closed || ! $hours->opens_at || ! $hours->closes_at) {
                continue;
            }
            $open = $day->setTimeFromTimeString($hours->opens_at);
            $close = $day->setTimeFromTimeString($hours->closes_at);
            if ($close->lte($open)) {
                $close = $close->addDay();
            }
            if ($from->gte($open) && $from->lt($close) && ($closingTime === null || $close->gt($closingTime))) {
                $closingTime = $close;
            }
        }

        return $closingTime;
    }

    public function conflicts(int $tableId, CarbonImmutable $from, CarbonImmutable $to, ?int $except = null): bool
    {
        return Reservation::where('billiard_table_id', $tableId)->when($except, fn ($q) => $q->where('id', '!=', $except))
            ->blocking()->overlapping($from, $to)->exists()
            || $this->blocksForTable($tableId)
                ->where('starts_at', '<', $to)->where('ends_at', '>', $from)->exists();
    }

    private function blocksForTable(int $tableId): Builder
    {
        return AvailabilityBlock::where(fn ($q) => $q->whereNull('billiard_table_id')->orWhere('billiard_table_id', $tableId));
    }

    public function available(string $start, int $minutes): Collection
    {
        if (! $this->enabled()) {
            return collect();
        }
        [$from,$to] = $this->window($start, $minutes);

        return BilliardTable::with('serviceRate')->where('is_active', true)->get()->filter(fn (BilliardTable $table) => $this->eligible($table) && ! $this->conflicts($table->id, $from, $to));
    }

    public function quote(int $tableId, string $start, int $minutes, bool $walkIn = false): array
    {
        if (! $walkIn && ! $this->enabled()) {
            $this->fail('Online booking is not open yet.');
        }
        [$from,$to] = $this->window($start, $minutes, $walkIn);
        $table = BilliardTable::with('serviceRate')->find($tableId);
        if (! $table || ! $this->eligible($table) || $this->conflicts($tableId, $from, $to)) {
            $this->fail('This table is no longer available for that time.');
        }
        $rate = $table->serviceRate->amount_minor;

        return ['billiard_table_id' => $table->id, 'table_name' => $table->name, 'starts_at' => $from->format('Y-m-d H:i:s'), 'ends_at' => $to->format('Y-m-d H:i:s'), 'minutes' => $minutes, 'hourly_rate' => $rate, 'total' => intdiv($rate * $minutes + 59, 60)];
    }

    public function create(array $data, ?User $staff = null): Reservation
    {
        if ($staff) {
            abort_unless($staff->is_staff, 403);
        }
        Validator::make($data, ['billiard_table_id' => 'required|integer', 'starts_at' => 'required|date', 'minutes' => 'required|integer|min:15|max:480|multiple_of:15', 'customer_name' => 'required|string|max:150', 'phone' => ['required', 'string', 'max:32', 'regex:/^\+?[0-9 ()-]{7,32}$/'], 'submission_key' => 'required|uuid', 'hourly_rate' => 'required|integer|min:0'])->validate();

        return DB::transaction(function () use ($data, $staff): Reservation {
            $this->lockScheduleForWrite();
            $existing = Reservation::where('submission_key', $data['submission_key'])->first();
            if ($existing) {
                if ($existing->billiard_table_id != $data['billiard_table_id'] || $existing->customer_name !== $data['customer_name'] || $existing->phone !== $data['phone'] || ! $existing->starts_at->equalTo(CarbonImmutable::parse($data['starts_at'])) || (int) $existing->starts_at->diffInMinutes($existing->ends_at) !== (int) $data['minutes']) {
                    $this->fail('This submission has already been used. Please start again.');
                }
                $this->expire();

                return $existing->fresh();
            }
            $this->expire();
            $quote = $this->quote((int) $data['billiard_table_id'], $data['starts_at'], (int) $data['minutes'], (bool) $staff);
            if ((int) $data['hourly_rate'] !== $quote['hourly_rate']) {
                $this->fail('The rate changed. Please review the new price before submitting.');
            }
            $hold = (int) (BusinessSetting::where('key', 'hold_minutes')->first()?->value ?? 60);
            $expiry = CarbonImmutable::now()->addMinutes(max(1, min(1440, $hold)))->min(CarbonImmutable::parse($quote['starts_at']));

            return Reservation::create([
                'reference' => Str::random(32), 'submission_key' => $data['submission_key'], 'billiard_table_id' => $quote['billiard_table_id'],
                'customer_name' => $data['customer_name'], 'phone' => $data['phone'], 'starts_at' => $quote['starts_at'], 'ends_at' => $quote['ends_at'],
                'quoted_hourly_rate_minor' => $quote['hourly_rate'], 'quoted_total_minor' => $quote['total'], 'currency' => 'PHP',
                'status' => $staff ? 'confirmed' : 'pending', 'source' => $staff ? 'walk_in' : 'website', 'hold_expires_at' => $staff ? null : $expiry,
                'reviewed_by' => $staff?->id, 'reviewed_at' => $staff ? now() : null,
            ]);
        }, 3);
    }

    public function transition(Reservation $reservation, string $action, User $staff): void
    {
        abort_unless($staff->is_staff, 403);
        DB::transaction(function () use ($reservation, $action, $staff): void {
            $this->lockScheduleForWrite();
            $this->expire();
            $record = Reservation::lockForUpdate()->findOrFail($reservation->id);
            if (! in_array($action, ['confirmed', 'rejected', 'cancelled']) || ! in_array($record->status, $action === 'cancelled' ? ['pending', 'confirmed'] : ['pending'])) {
                $this->fail('This request can no longer be changed with that action.');
            }
            if ($action === 'confirmed' && ($record->starts_at->lte(now()) || $this->conflicts($record->billiard_table_id, $record->starts_at, $record->ends_at, $record->id))) {
                $this->fail('This slot is no longer available.');
            }
            $record->update(['status' => $action, 'hold_expires_at' => null, 'reviewed_by' => $staff->id, 'reviewed_at' => now()]);
        }, 3);
    }

    /** @return array{ends_at: CarbonImmutable, seconds: int, total_minor: int, shortened: bool, key: string}|null */
    public function sessionOffer(Reservation $record, CarbonImmutable $start): ?array
    {
        $close = $this->closingTime($start);
        if ($close === null || ! $record->billiardTable?->is_active) {
            return null;
        }

        $bookedSeconds = $record->ends_at->timestamp - $record->starts_at->timestamp;
        $end = $start->addSeconds($bookedSeconds)->min($close);
        $nextBooking = Reservation::where('billiard_table_id', $record->billiard_table_id)
            ->whereKeyNot($record->id)->blocking()->overlapping($start, $end)
            ->orderByRaw(Reservation::EFFECTIVE_START)->first();
        $nextBlock = $this->blocksForTable($record->billiard_table_id)
            ->where('starts_at', '<', $end)->where('ends_at', '>', $start)->orderBy('starts_at')->first();
        if ($nextBooking) {
            $end = $end->min($nextBooking->effectiveStart());
        }
        if ($nextBlock) {
            $end = $end->min($nextBlock->starts_at);
        }

        $seconds = $end->timestamp - $start->timestamp;
        if ($seconds < 900) {
            return null;
        }

        $shortened = $seconds < $bookedSeconds;
        $blocks = intdiv($seconds + 899, 900);
        $total = $shortened ? intdiv($record->quoted_hourly_rate_minor * $blocks + 3, 4) : $record->quoted_total_minor;

        return ['ends_at' => $end, 'seconds' => $seconds, 'total_minor' => $total, 'shortened' => $shortened,
            'key' => $shortened ? $end->timestamp.':'.$total : 'full'];
    }

    private function startSession(Reservation $record, CarbonImmutable $start, ?string $agreedOffer): void
    {
        $offer = $this->sessionOffer($record, $start);
        if ($offer === null) {
            $this->fail('There are fewer than 15 minutes available on this table. Offer another table or time.');
        }
        if (($offer['shortened'] || $agreedOffer !== null) && $agreedOffer !== $offer['key']) {
            $this->fail('Reopen this action to review the current offer and confirm the guest agrees.');
        }

        $record->fill(['session_starts_at' => $start, 'session_ends_at' => $offer['ends_at'], 'session_total_minor' => $offer['total_minor']]);
    }

    public function checkIn(Reservation $reservation, User $staff, ?string $agreedOffer = null): void
    {
        abort_unless($staff->is_staff, 403);

        DB::transaction(function () use ($reservation, $agreedOffer): void {
            $this->lockScheduleForWrite();
            $record = Reservation::lockForUpdate()->findOrFail($reservation->id);
            $now = CarbonImmutable::now();
            if (! $record->canCheckIn($now)) {
                $this->fail('This reservation is no longer eligible for check-in.');
            }
            if ($record->session_starts_at === null) {
                $this->startSession($record, $now, $agreedOffer);
            }

            $record->fill(['checked_in_at' => $now])->save();
        }, 3);
    }

    public function recordLateCall(Reservation $reservation, string $outcome, bool $walkInWaiting, User $staff, ?string $agreedOffer = null): void
    {
        abort_unless($staff->is_staff, 403);
        if (! $walkInWaiting || ! in_array($outcome, ['no_answer', 'declined', 'coming'], true)) {
            $this->fail('Choose a call outcome and confirm a walk-in is waiting and needs this table.');
        }

        DB::transaction(function () use ($reservation, $outcome, $agreedOffer): void {
            $this->lockScheduleForWrite();
            $record = Reservation::lockForUpdate()->findOrFail($reservation->id);
            $now = CarbonImmutable::now();
            if (! $record->canRecordLateCall($now)
                || ($record->session_starts_at !== null && $outcome !== 'declined')
                || ($outcome === 'no_answer' && $record->late_contacted_at !== null)) {
                $this->fail('This call outcome is no longer available for the reservation.');
            }

            if ($outcome === 'coming') {
                $this->startSession($record, $now, $agreedOffer);
            }

            $record->fill(['late_contacted_at' => $now, 'late_contact_outcome' => $outcome]);
            if ($outcome === 'declined') {
                $record->status = 'cancelled';
            }
            $record->save();
        }, 3);
    }

    public function releaseNoShow(Reservation $reservation, bool $walkInWaiting, User $staff): void
    {
        abort_unless($staff->is_staff, 403);
        if (! $walkInWaiting) {
            $this->fail('Confirm a walk-in is still waiting and needs this table.');
        }

        DB::transaction(function () use ($reservation): void {
            $this->lockScheduleForWrite();
            $record = Reservation::lockForUpdate()->findOrFail($reservation->id);
            if (! $record->canReleaseNoShow(CarbonImmutable::now())) {
                $this->fail('This reservation is no longer eligible for release as a no-show.');
            }

            $record->update(['status' => 'no_show']);
        }, 3);
    }

    public function block(array $data, User $staff): AvailabilityBlock
    {
        abort_unless($staff->is_staff, 403);
        Validator::make($data, ['billiard_table_id' => 'nullable|exists:billiard_tables,id', 'type' => 'required|in:maintenance,closure,tournament', 'reason' => 'required|string|max:255', 'starts_at' => 'required|date', 'ends_at' => 'required|date|after:starts_at'])->validate();

        return DB::transaction(function () use ($data, $staff): AvailabilityBlock {
            $this->lockScheduleForWrite();
            $this->expire();
            $from = CarbonImmutable::parse($data['starts_at']);
            $to = CarbonImmutable::parse($data['ends_at']);
            $conflict = Reservation::when($data['billiard_table_id'] ?? null, fn ($q, $id) => $q->where('billiard_table_id', $id))
                ->blocking()->overlapping($from, $to)->exists();
            if ($conflict) {
                $this->fail('Resolve overlapping bookings before blocking this time.');
            }

            return AvailabilityBlock::create([...$data, 'created_by' => $staff->id]);
        }, 3);
    }

    public function expire(): int
    {
        return Reservation::where('status', 'pending')->where('hold_expires_at', '<=', now())->update(['status' => 'expired', 'hold_expires_at' => null, 'updated_at' => now()]);
    }

    private function eligible(BilliardTable $table): bool
    {
        $rate = $table->serviceRate;

        return $table->is_active && $rate && $rate->is_verified && $rate->is_active && $rate->currency === 'PHP' && $rate->unit === 'table/hour' && $rate->amount_minor >= 0;
    }

    private function fail(string $message): never
    {
        throw ValidationException::withMessages(['booking' => $message]);
    }
}
