<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class MotorflashCommercialErrorReceipt extends Model
{
    protected $fillable = [
        'source_event_id',
        'source_workflow',
        'received_at',
    ];

    protected function casts(): array
    {
        return [
            'received_at' => 'datetime',
        ];
    }
}
