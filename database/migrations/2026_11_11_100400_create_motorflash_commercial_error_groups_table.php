<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('motorflash_commercial_error_groups', function (Blueprint $table): void {
            $table->id();
            $table->string('error_type', 80);
            $table->string('searched_email_normalized', 191);
            $table->string('status', 20)->default('open');
            $table->timestamp('in_process_at')->nullable();
            $table->unsignedBigInteger('in_process_by_user_id')->nullable();
            $table->timestamps();

            $table->unique(['error_type', 'searched_email_normalized'], 'mf_comm_group_uq');
            $table->index(['status', 'updated_at'], 'mf_comm_group_status_idx');
        });

        if (Schema::hasTable('motorflash_commercial_error_events')) {
            $timestamp = now();

            DB::table('motorflash_commercial_error_events')
                ->select(['error_type', 'searched_email_normalized'])
                ->whereNotNull('searched_email_normalized')
                ->groupBy('error_type', 'searched_email_normalized')
                ->orderBy('error_type')
                ->orderBy('searched_email_normalized')
                ->each(function (object $eventGroup) use ($timestamp): void {
                    DB::table('motorflash_commercial_error_groups')->insertOrIgnore([
                        'error_type' => $eventGroup->error_type,
                        'searched_email_normalized' => $eventGroup->searched_email_normalized,
                        'status' => 'open',
                        'created_at' => $timestamp,
                        'updated_at' => $timestamp,
                    ]);
                });
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('motorflash_commercial_error_groups');
    }
};
