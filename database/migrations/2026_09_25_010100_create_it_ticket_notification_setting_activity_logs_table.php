<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        $tableName = 'it_ticket_notification_setting_activity_logs';

        if (! Schema::hasTable($tableName)) {
            Schema::create($tableName, function (Blueprint $table): void {
                $table->id();
                $table->string('action');
                $table->foreignId('actor_user_id')->nullable()->constrained('users')->nullOnDelete();
                $table->string('actor_name');
                $table->string('actor_email')->nullable();
                $table->json('changes');
                $table->timestamp('created_at')->useCurrent();

                $table->index(['action', 'created_at']);
                $table->index(['actor_user_id', 'created_at']);
            });

            return;
        }

        $requiredColumns = [
            'id',
            'action',
            'actor_user_id',
            'actor_name',
            'actor_email',
            'changes',
            'created_at',
        ];

        if (! Schema::hasColumns($tableName, $requiredColumns)) {
            throw new \RuntimeException(sprintf(
                'La tabla %s ya existe, pero no tiene todas las columnas esperadas. No se han aplicado cambios.',
                $tableName,
            ));
        }

        if (! Schema::hasTable('users')) {
            throw new \RuntimeException('No se puede reparar la foreign key de actor_user_id porque no existe la tabla users. No se han aplicado cambios.');
        }

        $foreignKeyState = $this->foreignKeyState($tableName);

        if ($foreignKeyState === 'incompatible') {
            throw new \RuntimeException('La tabla it_ticket_notification_setting_activity_logs ya tiene una foreign key incompatible sobre actor_user_id. No se han aplicado cambios.');
        }

        if ($foreignKeyState === 'missing' && DB::table($tableName)
            ->whereNotNull('actor_user_id')
            ->whereNotExists(function ($query): void {
                $query->selectRaw('1')
                    ->from('users')
                    ->whereColumn('users.id', 'it_ticket_notification_setting_activity_logs.actor_user_id');
            })
            ->exists()) {
            throw new \RuntimeException('No se puede añadir la foreign key de actor_user_id: existen filas con usuarios inexistentes. Corrige esos datos antes de migrar. No se han aplicado cambios.');
        }

        $indexes = Schema::getIndexes($tableName);

        if (! $this->hasIndex($indexes, ['action', 'created_at'])) {
            Schema::table($tableName, function (Blueprint $table): void {
                $table->index(['action', 'created_at']);
            });
        }

        if (! $this->hasIndex($indexes, ['actor_user_id', 'created_at'])) {
            Schema::table($tableName, function (Blueprint $table): void {
                $table->index(['actor_user_id', 'created_at']);
            });
        }

        if ($foreignKeyState === 'missing') {
            Schema::table($tableName, function (Blueprint $table): void {
                $table->foreign('actor_user_id')
                    ->references('id')
                    ->on('users')
                    ->nullOnDelete();
            });
        }
    }

    /** @param array<int, array{columns?: array<int, string>}> $indexes */
    private function hasIndex(array $indexes, array $columns): bool
    {
        foreach ($indexes as $index) {
            if (($index['columns'] ?? []) === $columns) {
                return true;
            }
        }

        return false;
    }

    private function foreignKeyState(string $tableName): string
    {
        foreach (Schema::getForeignKeys($tableName) as $foreignKey) {
            $columns = $foreignKey['columns'] ?? [];

            if ($columns !== ['actor_user_id']) {
                continue;
            }

            return ($foreignKey['foreign_table'] ?? null) === 'users'
                && ($foreignKey['foreign_columns'] ?? []) === ['id']
                ? 'present'
                : 'incompatible';
        }

        return 'missing';
    }

    public function down(): void
    {
        Schema::dropIfExists('it_ticket_notification_setting_activity_logs');
    }
};
