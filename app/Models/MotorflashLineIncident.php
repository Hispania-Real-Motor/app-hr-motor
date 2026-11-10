<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class MotorflashLineIncident extends Model
{
    public const TYPE_META_LINE_DISCONNECTED = 'meta_line_disconnected';

    public const STATUS_OPEN = 'open';
    public const STATUS_IN_PROCESS = 'in_process';
    public const STATUS_CLOSED = 'closed';

    protected $fillable = [
        'error_type',
        'phone_normalized',
        'phone_original',
        'line_name',
        'company',
        'status',
        'last_error_detail',
        'first_error_at',
        'last_error_at',
        'line_since_at',
        'in_process_at',
        'in_process_by_user_id',
        'closed_at',
    ];

    protected function casts(): array
    {
        return [
            'first_error_at' => 'datetime',
            'last_error_at' => 'datetime',
            'line_since_at' => 'datetime',
            'in_process_at' => 'datetime',
            'closed_at' => 'datetime',
        ];
    }

    public function inProcessBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'in_process_by_user_id');
    }

    public function histories(): \Illuminate\Database\Eloquent\Relations\HasMany
    {
        return $this->hasMany(MotorflashLineIncidentHistory::class, 'incident_id');
    }
}
