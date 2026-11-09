<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class EnreachErrorEvent extends Model
{
    public const TYPE_COMMERCIAL_PHONE_NOT_FOUND_IN_SALESFORCE = 'commercial_phone_not_found_in_salesforce';

    /** @deprecated Use TYPE_COMMERCIAL_PHONE_NOT_FOUND_IN_SALESFORCE. */
    public const TYPE_COMMERCIAL_PHONE_NOT_FOUND = self::TYPE_COMMERCIAL_PHONE_NOT_FOUND_IN_SALESFORCE;

    public const DIRECTION_INCOMING = 'incoming';

    public const DIRECTION_OUTGOING = 'outgoing';

    protected $fillable = [
        'error_type',
        'searched_phone',
        'phone_normalized',
        'occurred_at',
        'direction',
        'other_party_phone',
        'source_event_id',
        'source_workflow',
    ];

    protected function casts(): array
    {
        return [
            'occurred_at' => 'datetime',
        ];
    }

    protected static function booted(): void
    {
        static::saving(function (self $event): void {
            $event->phone_normalized = self::normalizePhone($event->searched_phone);
        });
    }

    public static function normalizePhone(mixed $value): ?string
    {
        $normalized = preg_replace('/\D+/', '', trim((string) $value));

        return $normalized === '' ? null : $normalized;
    }
}
