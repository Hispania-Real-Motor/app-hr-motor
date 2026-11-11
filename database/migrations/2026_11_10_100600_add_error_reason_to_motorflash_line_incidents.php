<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('motorflash_line_incidents') && ! Schema::hasColumn('motorflash_line_incidents', 'error_reason')) {
            Schema::table('motorflash_line_incidents', function (Blueprint $table): void {
                $table->string('error_reason', 40)->nullable()->after('error_type');
            });
        }

        if (Schema::hasTable('motorflash_line_incidents')) {
            DB::table('motorflash_line_incidents')
                ->whereNull('error_reason')
                ->where('error_type', 'meta_line_disconnected')
                ->update(['error_reason' => 'No encontrada']);
        }
    }

    public function down(): void
    {
        if (Schema::hasTable('motorflash_line_incidents') && Schema::hasColumn('motorflash_line_incidents', 'error_reason')) {
            Schema::table('motorflash_line_incidents', function (Blueprint $table): void {
                $table->dropColumn('error_reason');
            });
        }
    }
};
