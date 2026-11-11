<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('motorflash_commercial_error_events', function (Blueprint $table): void {
            $table->id();
            $table->string('error_type', 80);
            $table->string('searched_email', 191);
            $table->string('searched_email_normalized', 191);
            $table->timestamp('occurred_at');
            $table->string('source_event_id', 191);
            $table->string('source_workflow', 100);
            $table->timestamps();

            $table->unique(['source_workflow', 'source_event_id'], 'mf_comm_event_uq');
            $table->index(['error_type', 'searched_email_normalized', 'occurred_at'], 'mf_comm_email_grouping');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('motorflash_commercial_error_events');
    }
};
