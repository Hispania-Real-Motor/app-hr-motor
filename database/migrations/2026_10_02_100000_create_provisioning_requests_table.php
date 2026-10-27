<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('provisioning_requests', function (Blueprint $table): void {
            $table->id();
            $table->string('type')->index();
            $table->string('status')->index();
            $table->foreignId('requested_by_user_id')->constrained('users');
            $table->foreignId('provisioned_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->json('employee_data');
            $table->text('error_message')->nullable();
            $table->timestamp('started_at')->nullable();
            $table->timestamp('finished_at')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('provisioning_requests');
    }
};
