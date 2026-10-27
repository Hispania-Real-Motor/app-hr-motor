<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('provisioning_request_steps', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('provisioning_request_id')->constrained()->cascadeOnDelete();
            $table->string('platform');
            $table->string('status')->index();
            $table->text('message')->nullable();
            $table->text('error_message')->nullable();
            $table->json('technical_data')->nullable();
            $table->timestamp('started_at')->nullable();
            $table->timestamp('finished_at')->nullable();
            $table->timestamps();

            $table->unique(['provisioning_request_id', 'platform']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('provisioning_request_steps');
    }
};
