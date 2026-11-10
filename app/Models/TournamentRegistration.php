<?php

namespace App\Models;

use Database\Factories\TournamentRegistrationFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class TournamentRegistration extends Model
{
    /** @use HasFactory<TournamentRegistrationFactory> */
    use HasFactory;

    protected $fillable = ['tournament_id', 'reference', 'submission_key', 'full_name', 'phone', 'status', 'quoted_fee_minor', 'currency', 'payment_method', 'payment_reference', 'paid_at', 'payment_verified_by'];

    protected $hidden = ['full_name', 'phone', 'submission_key', 'payment_reference'];

    protected function casts(): array
    {
        return ['paid_at' => 'immutable_datetime', 'quoted_fee_minor' => 'integer'];
    }

    public function tournament(): BelongsTo
    {
        return $this->belongsTo(Tournament::class);
    }

    public function paymentVerifier(): BelongsTo
    {
        return $this->belongsTo(User::class, 'payment_verified_by');
    }
}
