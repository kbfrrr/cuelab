<?php

namespace App\Models;

use Database\Factories\ServiceRateFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class ServiceRate extends Model
{
    /** @use HasFactory<ServiceRateFactory> */
    use HasFactory;

    protected $fillable = ['name', 'description', 'amount_minor', 'currency', 'unit', 'is_verified', 'is_active'];

    protected function casts(): array
    {
        return ['amount_minor' => 'integer', 'is_verified' => 'boolean', 'is_active' => 'boolean'];
    }

    public function tables(): HasMany
    {
        return $this->hasMany(BilliardTable::class);
    }
}
