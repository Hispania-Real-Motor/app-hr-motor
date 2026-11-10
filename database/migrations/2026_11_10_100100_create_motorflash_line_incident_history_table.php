<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('motorflash_line_incident_history', function (Blueprint $table): void {
            $table->id();
            $table->unsignedBigInteger('incident_id');
            $table->char('report_fingerprint', 64)->nullable();
            $table->string('state', 20);
            $table->string('company')->nullable();
            $table->string('phone_original', 64)->nullable();
            $table->text('detail')->nullable();
            $table->timestamp('occurred_at')->nullable();
            $table->timestamps();

            $table->index(['incident_id', 'occurred_at'], 'mf_line_hist_incident_date_idx');
            $table->index('report_fingerprint', 'mf_line_hist_report_idx');
            $table->foreign('incident_id', 'mf_line_hist_incident_fk')
                ->references('id')
                ->on('motorflash_line_incidents')
                ->cascadeOnDelete();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('motorflash_line_incident_history');
    }
};
