<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Model;

class ItTicketNotificationSettingActivityLog extends Model
{
    use HasFactory;

    public const ACTION_UPDATED = 'updated';

    public $timestamps = false;

    protected $fillable = [
        'action',
        'actor_user_id',
        'actor_name',
        'actor_email',
        'changes',
        'created_at',
    ];

    protected function casts(): array
    {
        return [
            'changes' => 'array',
            'created_at' => 'datetime',
        ];
    }

    public function actor(): BelongsTo
    {
        return $this->belongsTo(User::class, 'actor_user_id');
    }
}
