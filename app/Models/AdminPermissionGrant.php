<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class AdminPermissionGrant extends Model
{
    use HasFactory;

    protected $fillable = [
        'permission_key',
        'user_id',
        'group_id',
        'group_role',
        'is_revoked',
        'granted_by_user_id',
    ];

    protected function casts(): array
    {
        return [
            'is_revoked' => 'boolean',
        ];
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function group(): BelongsTo
    {
        return $this->belongsTo(AdminPermissionGroup::class, 'group_id');
    }

    public function grantedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'granted_by_user_id');
    }
}
