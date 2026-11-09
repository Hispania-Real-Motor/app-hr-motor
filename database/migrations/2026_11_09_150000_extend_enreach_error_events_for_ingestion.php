<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('enreach_error_events', function (Blueprint $table): void {
            $table->string('source_event_id', 191)->nullable()->after('other_party_phone');
            $table->string('source_workflow', 100)->nullable()->after('source_event_id');
            $table->unique(['source_workflow', 'source_event_id'], 'enreach_error_events_source_unique');
        });

        Schema::table('enreach_error_events', function (Blueprint $table): void {
            $table->string('other_party_phone', 64)->nullable()->change();
        });
    }

    public function down(): void
    {
        Schema::table('enreach_error_events', function (Blueprint $table): void {
            $table->dropUnique('enreach_error_events_source_unique');
            $table->dropColumn(['source_event_id', 'source_workflow']);
        });
    }
};
