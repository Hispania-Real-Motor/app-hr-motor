<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('motorflash_line_incidents', function (Blueprint $table): void {
            $table->id();
            $table->string('error_type', 80);
            $table->string('phone_normalized', 32);
            $table->string('phone_original', 64);
            $table->string('company')->nullable();
            $table->string('status', 20)->default('open');
            $table->text('last_error_detail')->nullable();
            $table->timestamp('first_error_at')->nullable();
            $table->timestamp('last_error_at')->nullable();
            $table->timestamp('in_process_at')->nullable();
            $table->unsignedBigInteger('in_process_by_user_id')->nullable();
            $table->timestamp('closed_at')->nullable();
            $table->timestamps();

            $table->unique(['error_type', 'phone_normalized'], 'mf_line_inc_type_phone_uq');
            $table->index(['status', 'last_error_at'], 'mf_line_inc_status_last_idx');
            $table->foreign('in_process_by_user_id', 'mf_line_inc_process_user_fk')
                ->references('id')
                ->on('users')
                ->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('motorflash_line_incidents');
    }
};
