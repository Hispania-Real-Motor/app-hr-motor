<?php

namespace App\Services;

use App\Models\ItTicketNotificationSettingActivityLog;
use App\Models\User;
use Illuminate\Support\Facades\Schema;

class ItTicketNotificationSettingActivityLogWriter
{
    public function record(?User $actor, array $previousRecipients, array $recipients): void
    {
        if (! Schema::hasTable('it_ticket_notification_setting_activity_logs') || $previousRecipients === $recipients) {
            return;
        }

        ItTicketNotificationSettingActivityLog::query()->create([
            'action' => ItTicketNotificationSettingActivityLog::ACTION_UPDATED,
            'actor_user_id' => $actor?->id,
            'actor_name' => $actor?->name ?? 'Sistema',
            'actor_email' => $actor?->email,
            'changes' => [
                'recipients' => [
                    'from' => $previousRecipients,
                    'to' => $recipients,
                ],
            ],
            'created_at' => now(),
        ]);
    }
}
