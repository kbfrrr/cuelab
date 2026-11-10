<?php

namespace App\Models;

use Database\Factories\BilliardTableFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class BilliardTable extends Model
{
    /** @use HasFactory<BilliardTableFactory> */
    use HasFactory;

    protected $fillable = ['name', 'type', 'service_rate_id', 'description', 'is_active'];

    protected function casts(): array
    {
        return ['is_active' => 'boolean'];
    }

    public function serviceRate(): BelongsTo
    {
        return $this->belongsTo(ServiceRate::class);
    }

    public function reservations(): HasMany
    {
        return $this->hasMany(Reservation::class);
    }

    public function availabilityBlocks(): HasMany
    {
        return $this->hasMany(AvailabilityBlock::class);
    }
}
