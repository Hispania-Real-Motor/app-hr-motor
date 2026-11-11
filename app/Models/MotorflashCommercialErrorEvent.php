<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class MotorflashCommercialErrorEvent extends Model
{
    public const TYPE_COMMERCIAL_NOT_FOUND_IN_SALESFORCE = 'commercial_not_found_in_salesforce';
    public const SOURCE_WORKFLOW_N8N = 'n8n:q2ZU7EDI0LvtS5M3';

    protected $fillable = [
        'error_type',
        'searched_email',
        'searched_email_normalized',
        'occurred_at',
        'source_event_id',
        'source_workflow',
    ];

    protected function casts(): array
    {
        return [
            'occurred_at' => 'datetime',
        ];
    }

    public static function normalizeEmail(mixed $value): ?string
    {
        $normalized = mb_strtolower(trim((string) $value), 'UTF-8');

        return $normalized === '' ? null : $normalized;
    }
}
