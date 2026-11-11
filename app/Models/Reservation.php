<?php

namespace App\Models;

use Carbon\CarbonImmutable;
use Database\Factories\ReservationFactory;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class Reservation extends Model
{
    /** @use HasFactory<ReservationFactory> */
    use HasFactory;

    public const EFFECTIVE_START = 'COALESCE(session_starts_at, starts_at)';

    public const EFFECTIVE_END = 'COALESCE(session_ends_at, ends_at)';

    protected $fillable = ['reference', 'submission_key', 'billiard_table_id', 'customer_name', 'phone', 'starts_at', 'ends_at', 'status', 'source', 'quoted_hourly_rate_minor', 'quoted_total_minor', 'currency', 'hold_expires_at', 'reviewed_by', 'reviewed_at', 'staff_notes',
        'late_contacted_at', 'late_contact_outcome', 'checked_in_at', 'session_starts_at', 'session_ends_at', 'session_total_minor'];

    protected $hidden = ['customer_name', 'phone', 'submission_key', 'staff_notes'];

    protected function casts(): array
    {
        return ['starts_at' => 'immutable_datetime', 'ends_at' => 'immutable_datetime', 'hold_expires_at' => 'immutable_datetime', 'reviewed_at' => 'immutable_datetime', 'quoted_hourly_rate_minor' => 'integer', 'quoted_total_minor' => 'integer',
            'late_contacted_at' => 'immutable_datetime', 'checked_in_at' => 'immutable_datetime',
            'session_starts_at' => 'immutable_datetime', 'session_ends_at' => 'immutable_datetime', 'session_total_minor' => 'integer'];
    }

    public function scopeBlocking(Builder $query): Builder
    {
        return $query->where(fn (Builder $query): Builder => $query->where('status', 'confirmed')
            ->orWhere(fn (Builder $query): Builder => $query->where('status', 'pending')->where('hold_expires_at', '>', now())));
    }

    public function scopeOverlapping(Builder $query, CarbonImmutable $from, CarbonImmutable $to): Builder
    {
        return $query->whereRaw(self::EFFECTIVE_START.' < ?', [$to])
            ->whereRaw(self::EFFECTIVE_END.' > ?', [$from]);
    }

    public function statusHeading(): string
    {
        return match ($this->status) {
            'pending' => 'Awaiting approval.',
            'confirmed' => 'You’re booked.',
            'expired' => 'Your hold expired.',
            'rejected' => 'Request declined.',
            'cancelled' => 'Booking cancelled.',
            'no_show' => 'Your table was released.',
            default => 'Booking status.',
        };
    }

    public function effectiveStart(): CarbonImmutable
    {
        return $this->session_starts_at ?? $this->starts_at;
    }

    public function effectiveEnd(): CarbonImmutable
    {
        return $this->session_ends_at ?? $this->ends_at;
    }

    public function canCheckIn(CarbonImmutable $at): bool
    {
        return $this->status === 'confirmed' && $this->source === 'website'
            && $this->checked_in_at === null && $at->gte($this->starts_at) && $at->lt($this->effectiveEnd());
    }

    public function canRecordLateCall(CarbonImmutable $at): bool
    {
        return $this->canCheckIn($at) && $at->gte($this->starts_at->addMinutes(15));
    }

    public function replyDeadline(): ?CarbonImmutable
    {
        if ($this->status !== 'confirmed' || $this->checked_in_at !== null) {
            return null;
        }

        return match ($this->late_contact_outcome) {
            'no_answer' => $this->late_contacted_at?->addMinutes(5),
            'coming' => $this->session_starts_at?->addMinutes(5),
            default => null,
        };
    }

    public function canReleaseNoShow(CarbonImmutable $at): bool
    {
        $deadline = $this->replyDeadline();

        return $deadline !== null && $at->gte($deadline) && $this->canRecordLateCall($at);
    }

    public function billiardTable(): BelongsTo
    {
        return $this->belongsTo(BilliardTable::class);
    }

    public function reviewer(): BelongsTo
    {
        return $this->belongsTo(User::class, 'reviewed_by');
    }
}
