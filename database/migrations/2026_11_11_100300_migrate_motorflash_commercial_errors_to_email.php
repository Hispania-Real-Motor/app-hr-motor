<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        $tableName = 'motorflash_commercial_error_events';

        if (! Schema::hasTable($tableName)) {
            return;
        }

        if (! Schema::hasColumn($tableName, 'searched_email')) {
            Schema::table($tableName, function (Blueprint $table): void {
                $table->string('searched_email', 191)->nullable()->after('error_type');
            });
        }

        if (! Schema::hasColumn($tableName, 'searched_email_normalized')) {
            Schema::table($tableName, function (Blueprint $table): void {
                $table->string('searched_email_normalized', 191)->nullable()->after('searched_email');
            });
        }

        if (Schema::hasColumn($tableName, 'searched_identifier')) {
            DB::table($tableName)
                ->select(['id', 'searched_identifier'])
                ->whereNull('searched_email')
                ->orderBy('id')
                ->each(function (object $row) use ($tableName): void {
                    $email = trim((string) $row->searched_identifier);

                    if (filter_var($email, FILTER_VALIDATE_EMAIL) === false) {
                        return;
                    }

                    DB::table($tableName)
                        ->where('id', $row->id)
                        ->update([
                            'searched_email' => $row->searched_identifier,
                            'searched_email_normalized' => mb_strtolower($email, 'UTF-8'),
                        ]);
                });
        }

        $indexes = Schema::getIndexes($tableName);

        if (! $this->hasIndex($indexes, ['error_type', 'searched_email_normalized', 'occurred_at'])) {
            Schema::table($tableName, function (Blueprint $table): void {
                $table->index(
                    ['error_type', 'searched_email_normalized', 'occurred_at'],
                    'mf_comm_email_grouping',
                );
            });
        }
    }

    public function down(): void
    {
        // Existing event data is deliberately retained on rollback.
    }

    private function hasIndex(array $indexes, array $columns): bool
    {
        foreach ($indexes as $index) {
            if (($index['columns'] ?? []) === $columns) {
                return true;
            }
        }

        return false;
    }
};
