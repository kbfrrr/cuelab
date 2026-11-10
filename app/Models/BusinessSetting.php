<?php

namespace App\Models;

use Database\Factories\BusinessSettingFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class BusinessSetting extends Model
{
    /** @use HasFactory<BusinessSettingFactory> */
    use HasFactory;

    protected $fillable = ['key', 'value', 'is_verified'];

    protected function casts(): array
    {
        return ['value' => 'json', 'is_verified' => 'boolean'];
    }
}
