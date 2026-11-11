<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class MotorflashCommercialErrorGroup extends Model
{
    public const STATUS_OPEN = 'open';
    public const STATUS_IN_PROCESS = 'in_process';

    protected $fillable = [
        'error_type',
        'searched_email_normalized',
        'status',
        'in_process_at',
        'in_process_by_user_id',
    ];

    protected function casts(): array
    {
        return [
            'in_process_at' => 'datetime',
        ];
    }
}
