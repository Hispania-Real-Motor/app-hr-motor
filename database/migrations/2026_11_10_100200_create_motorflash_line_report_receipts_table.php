<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('motorflash_line_report_receipts', function (Blueprint $table): void {
            $table->id();
            $table->char('fingerprint', 64)->unique('mf_line_report_fingerprint_uq');
            $table->string('original_message_id')->nullable();
            $table->string('original_subject');
            $table->timestamp('report_occurred_at')->nullable();
            $table->unsignedInteger('total_lines');
            $table->unsignedInteger('problem_lines');
            $table->timestamp('received_at')->useCurrent();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('motorflash_line_report_receipts');
    }
};
