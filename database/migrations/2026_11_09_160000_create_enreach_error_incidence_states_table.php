<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('enreach_error_incidence_states', function (Blueprint $table): void {
            $table->id();
            $table->string('error_type', 80);
            $table->string('phone_normalized', 32);
            $table->timestamp('reviewed_at')->nullable();
            $table->timestamps();

            $table->unique(['error_type', 'phone_normalized'], 'enreach_inc_state_type_phone_uq');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('enreach_error_incidence_states');
    }
};
