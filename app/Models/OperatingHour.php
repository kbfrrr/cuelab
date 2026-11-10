<?php

namespace App\Models;

use Database\Factories\OperatingHourFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class OperatingHour extends Model
{
    /** @use HasFactory<OperatingHourFactory> */
    use HasFactory;

    protected $fillable = ['day_of_week', 'opens_at', 'closes_at', 'is_closed', 'is_verified'];

    protected function casts(): array
    {
        return ['day_of_week' => 'integer', 'is_closed' => 'boolean', 'is_verified' => 'boolean'];
    }
}
