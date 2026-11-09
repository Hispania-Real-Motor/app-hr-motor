<?php

namespace App\Filament\Widgets;

use App\Filament\Pages\MotorflashMessageErrorsPage;
use App\Filament\Pages\EnreachErrorsPage;
use App\Models\EnreachErrorEvent;
use App\Models\MotorflashMessageError;
use Closure;
use Filament\Widgets\StatsOverviewWidget as BaseWidget;
use Illuminate\Support\Facades\Log;
use Throwable;

class MotorflashMessageErrorsOverviewWidget extends BaseWidget
{
    protected static bool $isLazy = false;

    protected static ?int $sort = -1;

    protected ?string $heading = 'Errores IT';

    protected int | string | array $columnSpan = 'full';

    protected int | array | null $columns = [
        '@xl' => 4,
        '@lg' => 2,
        '@sm' => 1,
    ];

    protected ?string $pollingInterval = null;

    public static function canView(): bool
    {
        return app_user_has_admin_permission(auth()->user(), 'motorflash-message-errors.view')
            || app_user_has_admin_permission(auth()->user(), 'enreach-errors.view');
    }

    protected function getStats(): array
    {
        $stats = [];

        if (app_user_has_admin_permission(auth()->user(), 'motorflash-message-errors.view')) {
            $stats[] = $this->integrationStat(
                configKey: 'services.motorflash_message.integration_status',
                label: 'Motorflash Message',
                color: '#85C86D',
                url: MotorflashMessageErrorsPage::getUrl(),
                metric: 'motorflash_message_errors',
                query: fn (): int => MotorflashMessageError::query()->where('status', 'open')->count(),
            );
        }

        if (app_user_has_admin_permission(auth()->user(), 'enreach-errors.view')) {
            $stats[] = $this->integrationStat(
                configKey: 'services.enreach.integration_status',
                label: 'Enreach',
                color: '#8203F7',
                url: EnreachErrorsPage::getUrl(),
                metric: 'enreach_errors',
                query: fn (): int => EnreachErrorEvent::query()
                    ->where('error_type', EnreachErrorEvent::TYPE_COMMERCIAL_PHONE_NOT_FOUND)
                    ->whereNotNull('phone_normalized')
                    ->distinct()
                    ->count('phone_normalized'),
            );
        }

        return $stats;
    }

    private function integrationStat(string $configKey, string $label, string $color, string $url, string $metric, Closure $query): AdminOverviewStat
    {
        $stat = AdminOverviewStat::make($label, '')
            ->icon('heroicon-o-exclamation-triangle')
            ->color($color)
            ->url($url);

        if (config($configKey, 'pending') !== 'connected') {
            return $stat->description('Estado: Pendiente de integración');
        }

        return $stat->value($this->safeCount($metric, $query));
    }

    private function safeCount(string $metric, Closure $query): int | string
    {
        try {
            $value = $query();

            return is_int($value) && $value >= 0 ? $value : 'N/D';
        } catch (Throwable $exception) {
            report($exception);
            Log::error('No se pudo cargar la métrica de errores de integración del dashboard de Filament.', [
                'metric' => $metric,
                'exception' => $exception,
            ]);

            return 'N/D';
        }
    }
}
