<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('enreach_error_event_receipts', function (Blueprint $table): void {
            $table->id();
            $table->string('source_event_id', 191);
            $table->string('source_workflow', 100);
            $table->timestamp('received_at')->useCurrent();
            $table->timestamps();

            $table->unique(['source_workflow', 'source_event_id'], 'enreach_receipt_workflow_event_uq');
        });

        if (Schema::hasTable('enreach_error_events')) {
            DB::table('enreach_error_events')
                ->whereNotNull('source_event_id')
                ->whereNotNull('source_workflow')
                ->orderBy('id')
                ->chunkById(500, function ($events): void {
                    $now = now();
                    $rows = $events->map(fn ($event): array => [
                        'source_event_id' => $event->source_event_id,
                        'source_workflow' => $event->source_workflow,
                        'received_at' => $event->created_at ?? $now,
                        'created_at' => $now,
                        'updated_at' => $now,
                    ])->all();

                    DB::table('enreach_error_event_receipts')->insertOrIgnore($rows);
                });
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('enreach_error_event_receipts');
    }
};
