<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('enreach_error_events', function (Blueprint $table): void {
            $table->id();
            $table->string('error_type', 80)->index();
            $table->string('searched_phone', 64);
            $table->string('phone_normalized', 32)->index();
            $table->timestamp('occurred_at')->index();
            $table->string('direction', 20);
            $table->string('other_party_phone', 64);
            $table->timestamps();

            $table->index(['error_type', 'phone_normalized', 'occurred_at'], 'enreach_error_events_grouping_idx');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('enreach_error_events');
    }
};
