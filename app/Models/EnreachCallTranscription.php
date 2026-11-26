<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class EnreachCallTranscription extends Model
{
    protected $table = 'enreach_call_transcriptions';

    protected $connection = 'enreach_ai';

    protected $fillable = [
        'salesforce_task_id',
        'call_puid',
        'transcript_text',
        'transcript_raw',
        'audited_by_ai',
    ];

    protected function casts(): array
    {
        return [
            'transcript_raw' => 'array',
            'audited_by_ai' => 'boolean',
        ];
    }

    public function audit()
    {
        return $this->hasOne(EnreachCallAudit::class, 'transcription_id');
    }
}
