<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Model;

class ProvisioningStep extends Model
{
    use HasFactory;

    protected $table = 'provisioning_request_steps';

    public const STATUS_PENDING = 'pending';
    public const STATUS_PROCESSING = 'processing';
    public const STATUS_COMPLETED = 'completed';
    public const STATUS_FAILED = 'failed';
    public const STATUS_SKIPPED = 'skipped';

    protected $fillable = [
        'provisioning_request_id', 'platform', 'status', 'message',
        'error_message', 'technical_data', 'started_at', 'finished_at',
    ];

    protected function casts(): array
    {
        return ['technical_data' => 'array', 'started_at' => 'datetime', 'finished_at' => 'datetime'];
    }

    public function request(): BelongsTo { return $this->belongsTo(ProvisioningRequest::class, 'provisioning_request_id'); }
    public static function terminalStatuses(): array { return [self::STATUS_COMPLETED, self::STATUS_FAILED, self::STATUS_SKIPPED]; }
    public function getStatusLabelAttribute(): string { return match ($this->status) { self::STATUS_PENDING => 'Pendiente', self::STATUS_PROCESSING => 'Procesando', self::STATUS_COMPLETED => 'Completado', self::STATUS_FAILED => 'Error', self::STATUS_SKIPPED => 'Omitido', default => ucfirst((string) $this->status) }; }
}
