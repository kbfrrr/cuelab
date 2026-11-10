<?php

namespace App\Services;

use App\Models\AvailabilityBlock;
use App\Models\BilliardTable;
use App\Models\BusinessSetting;
use App\Models\OperatingHour;
use App\Models\Reservation;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

class ReservationService
{
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
        $withinHours = false;
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
            if ($from->gte($open) && $to->lte($close)) {
                $withinHours = true;
            }
        }
        if (! $withinHours) {
            $this->fail('That time is outside verified opening hours.');
        }

        return [$from, $to];
    }

    public function conflicts(int $tableId, CarbonImmutable $from, CarbonImmutable $to, ?int $except = null): bool
    {
        return Reservation::where('billiard_table_id', $tableId)->when($except, fn ($q) => $q->where('id', '!=', $except))
            ->where('starts_at', '<', $to)->where('ends_at', '>', $from)
            ->where(fn ($q) => $q->where('status', 'confirmed')->orWhere(fn ($q) => $q->where('status', 'pending')->where('hold_expires_at', '>', now())))->exists()
            || AvailabilityBlock::where(fn ($q) => $q->whereNull('billiard_table_id')->orWhere('billiard_table_id', $tableId))
                ->where('starts_at', '<', $to)->where('ends_at', '>', $from)->exists();
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
            $this->expire();
            $record = Reservation::findOrFail($reservation->id);
            if (! in_array($action, ['confirmed', 'rejected', 'cancelled']) || ! in_array($record->status, $action === 'cancelled' ? ['pending', 'confirmed'] : ['pending'])) {
                $this->fail('This request can no longer be changed with that action.');
            }
            if ($action === 'confirmed' && ($record->starts_at->lte(now()) || $this->conflicts($record->billiard_table_id, $record->starts_at, $record->ends_at, $record->id))) {
                $this->fail('This slot is no longer available.');
            }
            $record->update(['status' => $action, 'hold_expires_at' => null, 'reviewed_by' => $staff->id, 'reviewed_at' => now()]);
        }, 3);
    }

    public function block(array $data, User $staff): AvailabilityBlock
    {
        abort_unless($staff->is_staff, 403);
        Validator::make($data, ['billiard_table_id' => 'nullable|exists:billiard_tables,id', 'type' => 'required|in:maintenance,closure,tournament', 'reason' => 'required|string|max:255', 'starts_at' => 'required|date', 'ends_at' => 'required|date|after:starts_at'])->validate();

        return DB::transaction(function () use ($data, $staff): AvailabilityBlock {
            $this->expire();
            $conflict = Reservation::when($data['billiard_table_id'] ?? null, fn ($q, $id) => $q->where('billiard_table_id', $id))->whereIn('status', ['pending', 'confirmed'])->where('starts_at', '<', $data['ends_at'])->where('ends_at', '>', $data['starts_at'])->exists();
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
