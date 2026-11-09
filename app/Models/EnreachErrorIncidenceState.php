<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class EnreachErrorIncidenceState extends Model
{
    protected $fillable = [
        'error_type',
        'phone_normalized',
        'reviewed_at',
    ];

    protected function casts(): array
    {
        return [
            'reviewed_at' => 'datetime',
        ];
    }
}
