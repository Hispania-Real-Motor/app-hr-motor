<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class MotorflashLineIncidentHistory extends Model
{
    protected $table = 'motorflash_line_incident_history';

    public const STATE_ERROR = 'error';
    public const STATE_REOPENED = 'reopened';
    public const STATE_CLOSED = 'closed';
    public const STATE_IN_PROCESS = 'in_process';

    protected $fillable = [
        'incident_id',
        'report_fingerprint',
        'state',
        'company',
        'phone_original',
        'line_name',
        'detail',
        'occurred_at',
        'line_since_at',
    ];

    protected function casts(): array
    {
        return [
            'occurred_at' => 'datetime',
            'line_since_at' => 'datetime',
        ];
    }

    public function incident(): BelongsTo
    {
        return $this->belongsTo(MotorflashLineIncident::class, 'incident_id');
    }
}
