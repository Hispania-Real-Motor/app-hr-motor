<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('motorflash_commercial_error_receipts', function (Blueprint $table): void {
            $table->id();
            $table->string('source_event_id', 191);
            $table->string('source_workflow', 100);
            $table->timestamp('received_at')->useCurrent();
            $table->timestamps();

            $table->unique(['source_workflow', 'source_event_id'], 'mf_comm_receipt_uq');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('motorflash_commercial_error_receipts');
    }
};
