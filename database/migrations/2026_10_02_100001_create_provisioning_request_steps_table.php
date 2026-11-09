<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        $tableName = 'provisioning_request_steps';

        if (! Schema::hasTable($tableName)) {
            Schema::create($tableName, function (Blueprint $table): void {
                $table->id();
                $table->unsignedBigInteger('provisioning_request_id');
                $table->foreign('provisioning_request_id', 'prov_step_request_fk')
                    ->references('id')
                    ->on('provisioning_requests')
                    ->cascadeOnDelete();
                $table->string('platform');
                $table->string('status');
                $table->text('message')->nullable();
                $table->text('error_message')->nullable();
                $table->json('technical_data')->nullable();
                $table->timestamp('started_at')->nullable();
                $table->timestamp('finished_at')->nullable();
                $table->timestamps();

                $table->index('status', 'prov_step_status_idx');
                $table->unique(['provisioning_request_id', 'platform'], 'prov_step_request_platform_uq');
            });

            return;
        }

        $requiredColumns = [
            'id',
            'provisioning_request_id',
            'platform',
            'status',
            'message',
            'error_message',
            'technical_data',
            'started_at',
            'finished_at',
            'created_at',
            'updated_at',
        ];

        if (! Schema::hasColumns($tableName, $requiredColumns)) {
            throw new \RuntimeException(sprintf(
                'La tabla %s ya existe, pero no tiene todas las columnas esperadas. No se han aplicado cambios.',
                $tableName,
            ));
        }

        if (! Schema::hasTable('provisioning_requests')) {
            throw new \RuntimeException('No se puede reparar provisioning_request_steps porque no existe la tabla provisioning_requests. No se han aplicado cambios.');
        }

        $indexes = Schema::getIndexes($tableName);
        $hasStatusIndex = $this->hasIndex($indexes, ['status']);
        $hasUniqueIndex = $this->hasUniqueIndex($indexes, ['provisioning_request_id', 'platform']);
        $foreignKeyState = $this->foreignKeyState($tableName);

        if ($foreignKeyState === 'incompatible') {
            throw new \RuntimeException('La tabla provisioning_request_steps ya tiene una foreign key incompatible sobre provisioning_request_id. No se han aplicado cambios.');
        }

        if ($hasUniqueIndex === false && DB::table($tableName)
            ->select('provisioning_request_id', 'platform')
            ->groupBy('provisioning_request_id', 'platform')
            ->havingRaw('COUNT(*) > 1')
            ->exists()) {
            throw new \RuntimeException('No se puede añadir el índice único de provisioning_request_id y platform: existen combinaciones duplicadas. Corrige esos datos antes de migrar. No se han aplicado cambios.');
        }

        if ($foreignKeyState === 'missing' && DB::table($tableName)
            ->whereNotExists(function ($query): void {
                $query->selectRaw('1')
                    ->from('provisioning_requests')
                    ->whereColumn('provisioning_requests.id', 'provisioning_request_steps.provisioning_request_id');
            })
            ->exists()) {
            throw new \RuntimeException('No se puede añadir la foreign key de provisioning_request_id: existen pasos sin solicitud asociada. Corrige esos datos antes de migrar. No se han aplicado cambios.');
        }

        if (! $hasStatusIndex) {
            Schema::table($tableName, function (Blueprint $table): void {
                $table->index('status', 'prov_step_status_idx');
            });
        }

        if (! $hasUniqueIndex) {
            Schema::table($tableName, function (Blueprint $table): void {
                $table->unique(['provisioning_request_id', 'platform'], 'prov_step_request_platform_uq');
            });
        }

        if ($foreignKeyState === 'missing') {
            Schema::table($tableName, function (Blueprint $table): void {
                $table->foreign('provisioning_request_id', 'prov_step_request_fk')
                    ->references('id')
                    ->on('provisioning_requests')
                    ->cascadeOnDelete();
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

    /** @param array<int, array{columns?: array<int, string>, unique?: bool}> $indexes */
    private function hasUniqueIndex(array $indexes, array $columns): bool
    {
        foreach ($indexes as $index) {
            if (($index['columns'] ?? []) === $columns && ($index['unique'] ?? false)) {
                return true;
            }
        }

        return false;
    }

    private function foreignKeyState(string $tableName): string
    {
        foreach (Schema::getForeignKeys($tableName) as $foreignKey) {
            if (($foreignKey['columns'] ?? []) !== ['provisioning_request_id']) {
                continue;
            }

            return ($foreignKey['foreign_table'] ?? null) === 'provisioning_requests'
                && ($foreignKey['foreign_columns'] ?? []) === ['id']
                ? 'present'
                : 'incompatible';
        }

        return 'missing';
    }

    public function down(): void
    {
        Schema::dropIfExists('provisioning_request_steps');
    }
};
