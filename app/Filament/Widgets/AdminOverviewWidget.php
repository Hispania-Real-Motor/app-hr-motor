<?php

namespace App\Filament\Widgets;

use App\Models\CompanyChatGroup;
use App\Models\Dealership;
use App\Models\ItTicket;
use App\Models\User;
use App\Filament\Widgets\AdminOverviewStat;
use Closure;
use Filament\Widgets\StatsOverviewWidget as BaseWidget;
use Illuminate\Support\Facades\Log;
use Throwable;

class AdminOverviewWidget extends BaseWidget
{
    protected static bool $isLazy = false;

    protected static ?int $sort = -2;

    protected ?string $heading = 'Resumen general';

    protected int | string | array $columnSpan = 'full';

    protected int | array | null $columns = [
        '@xl' => 4,
        '@lg' => 2,
        '@sm' => 1,
    ];

    protected ?string $pollingInterval = null;

    public static function canView(): bool
    {
        return app_user_has_admin_permission(auth()->user(), 'dashboard.summary.view');
    }

    protected function getStats(): array
    {
        return [
            AdminOverviewStat::make('Usuarios activos', $this->safeCount(
                'active_users',
                fn (): int => User::query()
                    ->where('is_active', true)
                    ->whereNull('disabled_at')
                    ->count(),
            ))
                ->icon('heroicon-o-users'),
            AdminOverviewStat::make('Grupos activos', $this->safeCount(
                'active_groups',
                fn (): int => CompanyChatGroup::query()->count(),
            ))
                ->icon('heroicon-o-user-group'),
            AdminOverviewStat::make('Delegaciones activas', $this->safeCount(
                'active_dealerships',
                fn (): int => Dealership::query()->count(),
            ))
                ->icon('heroicon-o-building-office-2'),
            AdminOverviewStat::make('Incidencias resueltas', $this->safeCount(
                'resolved_tickets',
                fn (): int => ItTicket::query()
                    ->whereIn('status', ['closed', 'clausurado'])
                    ->count(),
            ))
                ->icon('heroicon-o-check-circle'),
        ];
    }

    private function safeCount(string $metric, Closure $query): int | string
    {
        try {
            $value = $query();

            return is_int($value) && $value >= 0 ? $value : 'N/D';
        } catch (Throwable $exception) {
            report($exception);
            Log::error('No se pudo cargar una métrica del dashboard de Filament.', [
                'metric' => $metric,
                'exception' => $exception,
            ]);

            return 'N/D';
        }
    }
}
