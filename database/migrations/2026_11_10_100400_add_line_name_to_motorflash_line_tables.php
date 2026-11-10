<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('motorflash_line_incidents') && ! Schema::hasColumn('motorflash_line_incidents', 'line_name')) {
            Schema::table('motorflash_line_incidents', function (Blueprint $table): void {
                $table->string('line_name')->nullable()->after('phone_original');
            });
        }

        if (Schema::hasTable('motorflash_line_incident_history') && ! Schema::hasColumn('motorflash_line_incident_history', 'line_name')) {
            Schema::table('motorflash_line_incident_history', function (Blueprint $table): void {
                $table->string('line_name')->nullable()->after('phone_original');
            });
        }

        if (Schema::hasTable('motorflash_line_incidents')) {
            DB::table('motorflash_line_incidents')
                ->whereNull('line_name')
                ->whereNotNull('company')
                ->update(['line_name' => DB::raw('company')]);
        }

        if (Schema::hasTable('motorflash_line_incident_history')) {
            DB::table('motorflash_line_incident_history')
                ->whereNull('line_name')
                ->whereNotNull('company')
                ->update(['line_name' => DB::raw('company')]);
        }
    }

    public function down(): void
    {
        if (Schema::hasTable('motorflash_line_incidents') && Schema::hasColumn('motorflash_line_incidents', 'line_name')) {
            Schema::table('motorflash_line_incidents', function (Blueprint $table): void {
                $table->dropColumn('line_name');
            });
        }

        if (Schema::hasTable('motorflash_line_incident_history') && Schema::hasColumn('motorflash_line_incident_history', 'line_name')) {
            Schema::table('motorflash_line_incident_history', function (Blueprint $table): void {
                $table->dropColumn('line_name');
            });
        }
    }
};
