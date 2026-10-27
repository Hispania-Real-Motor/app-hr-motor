<?php

namespace App\Jobs;

use App\Models\ProvisioningRequest;
use App\Models\ProvisioningStep;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Throwable;

class ProcessProvisioningStepJob implements ShouldQueue, ShouldBeUnique
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public function __construct(public readonly int $stepId) { $this->onQueue('provisioning'); }
    public function uniqueId(): string { return (string) $this->stepId; }

    public function handle(): void
    {
        $step = DB::transaction(function (): ?ProvisioningStep {
            $locked = ProvisioningStep::query()->lockForUpdate()->find($this->stepId);
            if (! $locked || in_array($locked->status, ProvisioningStep::terminalStatuses(), true)) return null;
            $locked->forceFill(['status' => ProvisioningStep::STATUS_PROCESSING, 'started_at' => $locked->started_at ?? now(), 'error_message' => null])->saveQuietly();
            return $locked->fresh('request');
        });

        if (! $step) return;
        $request = $step->request;
        Log::info('Provisioning step processing started.', ['request_id' => $request->id, 'step_id' => $step->id, 'platform' => $step->platform]);

        try {
            $definition = config("provisioning.platforms.{$step->platform}");
            if (! is_array($definition) || ! ($definition['enabled'] ?? false) || ! isset($definition['processor'])) {
                $result = ['status' => ProvisioningStep::STATUS_SKIPPED, 'message' => 'Este paso no está configurado actualmente.'];
            } else {
                $result = app($definition['processor'])->process($request, $step);
            }
            $step->forceFill(['status' => $result['status'], 'message' => $result['message'], 'finished_at' => now()])->saveQuietly();
            $request->refresh()->refreshStatus();
            Log::info('Provisioning step finished.', ['request_id' => $request->id, 'step_id' => $step->id, 'status' => $result['status']]);
        } catch (Throwable $exception) {
            $step->forceFill(['status' => ProvisioningStep::STATUS_FAILED, 'message' => 'No se ha podido completar este paso.', 'error_message' => $exception->getMessage(), 'technical_data' => ['exception' => $exception::class], 'finished_at' => now()])->saveQuietly();
            $request->refresh()->refreshStatus();
            Log::error('Provisioning step failed.', ['request_id' => $request->id, 'step_id' => $step->id, 'platform' => $step->platform, 'exception' => $exception::class]);
        }
    }
}
