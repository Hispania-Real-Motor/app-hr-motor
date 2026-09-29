<?php

namespace App\Filament\Pages;

use App\Services\GoogleBusinessProfileReviewService;
use App\Jobs\SyncGoogleBusinessProfileReviewsJob;
use BackedEnum;
use Filament\Pages\Page;
use Filament\Notifications\Notification;
use Illuminate\Support\Facades\Cache;
use Throwable;

class GoogleBusinessProfileConnectionPage extends Page
{
    protected static string|BackedEnum|null $navigationIcon = 'heroicon-o-globe-alt';

    protected static ?string $navigationLabel = 'Conexión Google Reseñas';

    protected static ?string $title = 'Conexión Google Reseñas';

    protected static ?string $slug = 'google-reviews-connection';

    protected static string|\UnitEnum|null $navigationGroup = 'Administración';

    protected static ?int $navigationSort = 8;

    protected static ?string $breadcrumb = 'Conexión Google Reseñas';

    protected string $view = 'filament.pages.google-business-profile-connection';

    public bool $connected = false;

    public ?string $accountName = null;

    public ?string $lastSyncedAt = null;

    public bool $isSyncing = false;

    public static function canAccess(): bool
    {
        return app_user_has_admin_permission(auth()->user(), 'reviews.google.manage');
    }

    public function mount(GoogleBusinessProfileReviewService $service): void
    {
        abort_unless(static::canAccess(), 403);

        $connection = $service->getConnection();
        $this->connected = $service->hasValidConnection();
        $this->accountName = $connection?->account_name;
        $this->lastSyncedAt = $connection?->last_synced_at?->format('d/m/Y H:i');
    }

    public function syncReviews(): void
    {
        abort_unless(static::canAccess(), 403);

        if (! app(GoogleBusinessProfileReviewService::class)->hasValidConnection()) {
            Notification::make()
                ->danger()
                ->title('Conecta Google antes de sincronizar las reseñas.')
                ->send();

            return;
        }

        if ($this->isSyncing) {
            return;
        }

        $lock = Cache::lock('google-business-profile-review-sync-dispatch', 30);

        if (! $lock->get()) {
            Notification::make()
                ->warning()
                ->title('Ya hay una sincronización de reseñas en curso.')
                ->body('Espera a que termine antes de volver a intentarlo.')
                ->send();

            return;
        }

        $this->isSyncing = true;

        try {
            SyncGoogleBusinessProfileReviewsJob::dispatch();

            Notification::make()
                ->success()
                ->title('Sincronización de reseñas solicitada.')
                ->body('Las reseñas se actualizarán en segundo plano.')
                ->send();
        } catch (Throwable $exception) {
            report($exception);

            Notification::make()
                ->danger()
                ->title('No se ha podido iniciar la sincronización de reseñas.')
                ->send();
        } finally {
            $this->isSyncing = false;
            $lock->release();
        }
    }
}
