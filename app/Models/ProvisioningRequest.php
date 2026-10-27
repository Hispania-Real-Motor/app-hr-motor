<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Model;

class ProvisioningRequest extends Model
{
    use HasFactory;

    public const TYPE_ONBOARDING = 'alta';
    public const STATUS_PENDING = 'pending';
    public const STATUS_PROCESSING = 'processing';
    public const STATUS_COMPLETED = 'completed';
    public const STATUS_COMPLETED_WITH_ERRORS = 'completed_with_errors';
    public const STATUS_FAILED = 'failed';

    protected $fillable = [
        'type', 'status', 'requested_by_user_id', 'provisioned_user_id',
        'employee_data', 'error_message', 'started_at', 'finished_at',
    ];

    protected function casts(): array
    {
        return [
            'employee_data' => 'array',
            'started_at' => 'datetime',
            'finished_at' => 'datetime',
        ];
    }

    public function requestedBy(): BelongsTo { return $this->belongsTo(User::class, 'requested_by_user_id'); }
    public function provisionedUser(): BelongsTo { return $this->belongsTo(User::class, 'provisioned_user_id'); }
    public function steps(): HasMany { return $this->hasMany(ProvisioningStep::class); }

    public function getProgressAttribute(): int
    {
        $total = $this->steps->count();
        if ($total === 0) return 0;
        $terminal = $this->steps->whereIn('status', ProvisioningStep::terminalStatuses())->count();
        return (int) round($terminal / $total * 100);
    }

    public function refreshStatus(): self
    {
        $steps = $this->steps()->get();
        $total = $steps->count();
        $terminal = $steps->whereIn('status', ProvisioningStep::terminalStatuses());
        $failed = $steps->where('status', ProvisioningStep::STATUS_FAILED);
        $completed = $steps->where('status', ProvisioningStep::STATUS_COMPLETED);

        $status = $this->status;
        if ($total === 0) $status = self::STATUS_FAILED;
        elseif ($terminal->count() < $total) $status = $this->started_at ? self::STATUS_PROCESSING : self::STATUS_PENDING;
        elseif ($failed->isEmpty()) $status = self::STATUS_COMPLETED;
        elseif ($completed->isNotEmpty()) $status = self::STATUS_COMPLETED_WITH_ERRORS;
        else $status = self::STATUS_FAILED;

        $this->forceFill([
            'status' => $status,
            'finished_at' => $terminal->count() === $total ? ($this->finished_at ?? now()) : null,
        ])->saveQuietly();
        return $this->refresh();
    }
}
