<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class MotorflashLineReportReceipt extends Model
{
    protected $fillable = [
        'fingerprint',
        'original_message_id',
        'original_subject',
        'report_occurred_at',
        'total_lines',
        'problem_lines',
        'received_at',
    ];

    protected function casts(): array
    {
        return [
            'report_occurred_at' => 'datetime',
            'received_at' => 'datetime',
        ];
    }
}
