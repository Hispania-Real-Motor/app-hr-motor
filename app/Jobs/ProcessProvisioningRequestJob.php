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
use Illuminate\Support\Facades\Log;

class ProcessProvisioningRequestJob implements ShouldQueue, ShouldBeUnique
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public function __construct(public readonly int $requestId) { $this->onQueue('provisioning'); }
    public function uniqueId(): string { return (string) $this->requestId; }

    public function handle(): void
    {
        $request = ProvisioningRequest::query()->find($this->requestId);
        if (! $request || in_array($request->status, [ProvisioningRequest::STATUS_COMPLETED, ProvisioningRequest::STATUS_COMPLETED_WITH_ERRORS, ProvisioningRequest::STATUS_FAILED], true)) return;

        $request->forceFill(['status' => ProvisioningRequest::STATUS_PROCESSING, 'started_at' => $request->started_at ?? now()])->saveQuietly();
        Log::info('Provisioning request processing started.', ['request_id' => $request->id]);

        foreach ($request->steps()->where('status', ProvisioningStep::STATUS_PENDING)->pluck('id') as $stepId) {
            ProcessProvisioningStepJob::dispatch((int) $stepId);
        }
    }

    public function failed(\Throwable $exception): void
    {
        $request = ProvisioningRequest::query()->find($this->requestId);
        if ($request) {
            $request->steps()->whereIn('status', [ProvisioningStep::STATUS_PENDING, ProvisioningStep::STATUS_PROCESSING])->update([
                'status' => ProvisioningStep::STATUS_FAILED,
                'message' => 'No se ha podido iniciar este paso.',
                'finished_at' => now(),
            ]);
            $request->forceFill([
                'status' => ProvisioningRequest::STATUS_FAILED,
                'error_message' => 'No se ha podido coordinar el procesamiento de la solicitud.',
                'finished_at' => now(),
            ])->saveQuietly();
        }
        Log::error('Provisioning request coordination failed.', ['request_id' => $this->requestId, 'exception' => $exception::class]);
    }
}
