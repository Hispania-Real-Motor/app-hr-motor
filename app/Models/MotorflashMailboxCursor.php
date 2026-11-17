<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class MotorflashMailboxCursor extends Model
{
    protected $fillable = [
        'mailbox_key',
        'last_uid',
    ];

    protected function casts(): array
    {
        return [
            'last_uid' => 'integer',
        ];
    }
}
