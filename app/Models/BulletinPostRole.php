<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class BulletinPostRole extends Model
{
    protected $fillable = [
        'bulletin_post_id',
        'role',
    ];

    public function bulletinPost(): BelongsTo
    {
        return $this->belongsTo(BulletinPost::class);
    }
}
