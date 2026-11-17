<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('motorflash_mailbox_cursors', function (Blueprint $table): void {
            $table->id();
            $table->string('mailbox_key')->unique();
            $table->unsignedBigInteger('last_uid');
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('motorflash_mailbox_cursors');
    }
};
