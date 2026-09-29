<?php

namespace App\Filament\Pages;

use App\Services\LeaderboardSyncService;
use BackedEnum;
use Filament\Notifications\Notification;
use Filament\Pages\Page;
use Illuminate\Support\Facades\Cache;
use Throwable;

class RankingsPage extends Page
{
    protected static string|BackedEnum|null $navigationIcon = 'heroicon-o-chart-bar';

    protected static ?string $navigationLabel = 'Rankings';

    protected static ?string $title = 'Rankings';

    protected static ?string $slug = 'rankings';

    protected static string|\UnitEnum|null $navigationGroup = 'Administración';

    protected static ?int $navigationSort = 7;

    protected static ?string $breadcrumb = 'Rankings';

    protected string $view = 'filament.pages.rankings';

    public bool $isSyncing = false;

    public static function canAccess(): bool
    {
        return app_user_has_admin_permission(auth()->user(), 'backoffice.rankings.manage');
    }

    public function syncRankings(): void
    {
        abort_unless(static::canAccess(), 403);

        if ($this->isSyncing) {
            return;
        }

        $lock = Cache::lock('leaderboard-sync', 600);

        if (! $lock->get()) {
            Notification::make()
                ->warning()
                ->title('Ya hay una actualización de rankings en curso.')
                ->body('Espera a que termine antes de volver a intentarlo.')
                ->send();

            return;
        }

        $this->isSyncing = true;

        try {
            $service = app(LeaderboardSyncService::class);

            if (! $service->hasSalesforceConnection()) {
                Notification::make()
                    ->danger()
                    ->title('Salesforce todavia no esta conectado. La app sigue operativa, pero el leaderboard no puede sincronizarse hasta completar la autorizacion.')
                    ->send();

                return;
            }

            $service->sync();

            Notification::make()
                ->success()
                ->title('Rankings de ventas, compras y coches actualizados correctamente.')
                ->send();
        } catch (Throwable $exception) {
            report($exception);

            Notification::make()
                ->danger()
                ->title('No se han podido sincronizar los rankings con Salesforce. Revisa la conexion o las consultas configuradas.')
                ->send();
        } finally {
            $this->isSyncing = false;
            $lock->release();
        }
    }
}
