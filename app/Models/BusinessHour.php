<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class BusinessHour extends Model
{
    protected $fillable = [
        'day_of_week',
        'is_closed',
        'opens_at',
        'closes_at',
    ];

    protected function casts(): array
    {
        return [
            'is_closed' => 'boolean',
        ];
    }
}
