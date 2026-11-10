<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('motorflash_line_incidents') && ! Schema::hasColumn('motorflash_line_incidents', 'line_since_at')) {
            Schema::table('motorflash_line_incidents', function (Blueprint $table): void {
                $table->timestamp('line_since_at')->nullable()->after('last_error_at');
            });
        }

        if (Schema::hasTable('motorflash_line_incident_history') && ! Schema::hasColumn('motorflash_line_incident_history', 'line_since_at')) {
            Schema::table('motorflash_line_incident_history', function (Blueprint $table): void {
                $table->timestamp('line_since_at')->nullable()->after('occurred_at');
            });
        }

        // Existing imports used the report's Desde value for last_error_at.
        if (Schema::hasTable('motorflash_line_incidents')) {
            DB::table('motorflash_line_incidents')
                ->whereNull('line_since_at')
                ->whereNotNull('last_error_at')
                ->update(['line_since_at' => DB::raw('last_error_at')]);
        }

        if (Schema::hasTable('motorflash_line_incident_history')) {
            DB::table('motorflash_line_incident_history')
                ->whereNull('line_since_at')
                ->whereIn('state', ['error', 'reopened'])
                ->whereNotNull('occurred_at')
                ->update(['line_since_at' => DB::raw('occurred_at')]);
        }
    }

    public function down(): void
    {
        if (Schema::hasTable('motorflash_line_incidents') && Schema::hasColumn('motorflash_line_incidents', 'line_since_at')) {
            Schema::table('motorflash_line_incidents', function (Blueprint $table): void {
                $table->dropColumn('line_since_at');
            });
        }

        if (Schema::hasTable('motorflash_line_incident_history') && Schema::hasColumn('motorflash_line_incident_history', 'line_since_at')) {
            Schema::table('motorflash_line_incident_history', function (Blueprint $table): void {
                $table->dropColumn('line_since_at');
            });
        }
    }
};
