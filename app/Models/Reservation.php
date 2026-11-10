<?php

namespace App\Models;

use Database\Factories\ReservationFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class Reservation extends Model
{
    /** @use HasFactory<ReservationFactory> */
    use HasFactory;

    protected $fillable = ['reference', 'submission_key', 'billiard_table_id', 'customer_name', 'phone', 'starts_at', 'ends_at', 'status', 'source', 'quoted_hourly_rate_minor', 'quoted_total_minor', 'currency', 'hold_expires_at', 'reviewed_by', 'reviewed_at', 'staff_notes'];

    protected $hidden = ['customer_name', 'phone', 'submission_key', 'staff_notes'];

    protected function casts(): array
    {
        return ['starts_at' => 'immutable_datetime', 'ends_at' => 'immutable_datetime', 'hold_expires_at' => 'immutable_datetime', 'reviewed_at' => 'immutable_datetime', 'quoted_hourly_rate_minor' => 'integer', 'quoted_total_minor' => 'integer'];
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
