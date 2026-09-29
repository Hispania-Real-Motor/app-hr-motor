<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Schema;

class ItTicketNotificationSetting extends Model
{
    use HasFactory;

    public const DEFAULT_RECIPIENTS = [
        'carlos.torres@hrmotor.es',
        'javier.arruabarrena@hrmotor.com',
    ];

    protected $fillable = [
        'recipients',
        'updated_by_user_id',
    ];

    protected function casts(): array
    {
        return [
            'recipients' => 'array',
        ];
    }

    public static function current(): self
    {
        if (! Schema::hasTable('it_ticket_notification_settings')) {
            return static::make(['recipients' => self::DEFAULT_RECIPIENTS]);
        }

        return static::query()->latest('updated_at')->first()
            ?? static::make(['recipients' => self::DEFAULT_RECIPIENTS]);
    }

    /**
     * @return array<int, string>
     */
    public static function recipients(): array
    {
        $recipients = static::current()->recipients ?? self::DEFAULT_RECIPIENTS;

        return array_values(array_filter(array_map(
            static fn (mixed $recipient): string => strtolower(trim((string) $recipient)),
            $recipients,
        )));
    }

    public function updatedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'updated_by_user_id');
    }
}
