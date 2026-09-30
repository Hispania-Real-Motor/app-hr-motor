<?php

namespace App\Jobs;

use App\Models\Dealership;
use App\Services\GoogleBusinessProfileReviewService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\Middleware\WithoutOverlapping;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;
use Throwable;

class SyncGoogleBusinessProfileReviewsJob implements ShouldQueue
{
    use Dispatchable;
    use InteractsWithQueue;
    use Queueable;
    use SerializesModels;

    public function __construct(
        public readonly ?int $dealershipId = null
    ) {
        $this->onQueue('google-business-profile-sync');
    }

    public function handle(GoogleBusinessProfileReviewService $service): void
    {
        $dealership = $this->dealershipId
            ? Dealership::query()->withoutSalamanca()->find($this->dealershipId)
            : null;

        if ($this->dealershipId && ! $dealership) {
            Log::warning('Google Business Profile sync job skipped because the dealership no longer exists.', [
                'dealership_id' => $this->dealershipId,
            ]);

            return;
        }

        try {
            $service->sync($dealership);
        } catch (Throwable $exception) {
            Log::error('Google Business Profile sync job failed.', [
                'dealership_id' => $this->dealershipId,
                'message' => $exception->getMessage(),
            ]);

            throw $exception;
        }
    }

    /**
     * @return array<int, WithoutOverlapping>
     */
    public function middleware(): array
    {
        return [
            (new WithoutOverlapping('google-business-profile-reviews-sync'))->expireAfter(900),
        ];
    }
}
