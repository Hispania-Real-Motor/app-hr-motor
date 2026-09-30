<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('it_ticket_notification_settings')) {
            return;
        }

        DB::table('it_ticket_notification_settings')
            ->whereJsonContains('recipients', 'javier.arruabarrena@hrmotor.com')
            ->whereJsonContains('recipients', 'carlos.torres@hrmotor.es')
            ->update([
                'recipients' => json_encode([
                    'carlos.torres@hrmotor.es',
                    'javier.arruabarrena@hrmotor.com',
                ], JSON_THROW_ON_ERROR),
                'updated_at' => now(),
            ]);
    }

    public function down(): void
    {
        // The recipient order must remain compatible with the original mail flow.
    }
};
