<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('it_ticket_notification_settings', function (Blueprint $table): void {
            $table->id();
            $table->json('recipients');
            $table->foreignId('updated_by_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
        });

        DB::table('it_ticket_notification_settings')->insert([
            'recipients' => json_encode([
                'carlos.torres@hrmotor.es',
                'javier.arruabarrena@hrmotor.com',
            ], JSON_THROW_ON_ERROR),
            'updated_by_user_id' => null,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    public function down(): void
    {
        Schema::dropIfExists('it_ticket_notification_settings');
    }
};
