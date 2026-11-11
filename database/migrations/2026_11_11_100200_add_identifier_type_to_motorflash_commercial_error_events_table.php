<?php

use Illuminate\Database\Migrations\Migration;

return new class extends Migration
{
    public function up(): void
    {
        // The email-specific schema is created by the preceding migration.
        // Existing installations are upgraded by the following compatibility migration.
    }

    public function down(): void
    {
        // Intentionally empty: this migration no longer changes the schema.
    }
};
