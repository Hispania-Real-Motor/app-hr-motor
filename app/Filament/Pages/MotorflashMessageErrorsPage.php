<?php

namespace App\Filament\Pages;

use App\Models\MotorflashCommercialErrorEvent;
use App\Models\MotorflashLineIncident;
use App\Models\MotorflashLineReportReceipt;
use BackedEnum;
use Filament\Pages\Dashboard;
use Filament\Pages\Page;
use Illuminate\Support\Facades\Log;

class MotorflashMessageErrorsPage extends Page
{
    protected static string|BackedEnum|null $navigationIcon = 'heroicon-o-exclamation-triangle';
    protected static ?string $navigationLabel = 'Errores de Motorflash Message';
    protected static ?string $title = 'Errores de Motorflash Message';
    protected static ?string $slug = 'motorflash-message-errors';
    protected static string|\UnitEnum|null $navigationGroup = 'Errores';
    protected static ?int $navigationSort = 10;
    protected static ?string $breadcrumb = 'Errores de Motorflash Message';
    protected string $view = 'filament.pages.motorflash-message-errors';

    public static function canAccess(): bool
    {
        return app_user_has_admin_permission(auth()->user(), 'motorflash-message-errors.view');
    }

    public function mount(): void
    {
        abort_unless(static::canAccess(), 403);
    }

    public function hydrate(): void
    {
        abort_unless(static::canAccess(), 403);
    }

    public function getBreadcrumbs(): array
    {
        return [
            Dashboard::getUrl() => 'Escritorio',
            self::getUrl() => 'Motorflash Message',
        ];
    }

    public function lineErrorsCategory(): array
    {
        $count = 'Pendiente de integración';

        try {
            if (MotorflashLineReportReceipt::query()->exists()) {
                $count = MotorflashLineIncident::query()
                    ->where('error_type', MotorflashLineIncident::TYPE_META_LINE_DISCONNECTED)
                    ->where('status', '!=', MotorflashLineIncident::STATUS_CLOSED)
                    ->whereNotNull('phone_normalized')
                    ->distinct()
                    ->count('phone_normalized');
            }
        } catch (\Throwable $exception) {
            report($exception);
            Log::error('No se pudo contar las líneas afectadas de Motorflash.', [
                'error_type' => MotorflashLineIncident::TYPE_META_LINE_DISCONNECTED,
                'exception' => $exception,
            ]);
            $count = 'N/D';
        }

        return [
            'label' => 'Líneas desconectadas de Meta',
            'count' => $count,
            'url' => MotorflashLineErrorsPage::getUrl(),
        ];
    }

    public function commercialErrorsCategory(): array
    {
        try {
            $count = MotorflashCommercialErrorGroup::query()
                ->whereIn('status', [
                    MotorflashCommercialErrorGroup::STATUS_OPEN,
                    MotorflashCommercialErrorGroup::STATUS_IN_PROCESS,
                ])
                ->count();
        } catch (\Throwable $exception) {
            report($exception);
            Log::error('No se pudo contar los comerciales afectados de Motorflash.', [
                'error_type' => MotorflashCommercialErrorEvent::TYPE_COMMERCIAL_NOT_FOUND_IN_SALESFORCE,
                'exception' => $exception,
            ]);
            $count = 'N/D';
        }

        return [
            'label' => 'Comerciales no encontrados en Salesforce',
            'count' => $count,
            'description' => 'Comerciales distintos afectados',
            'url' => MotorflashCommercialErrorsPage::getUrl(),
        ];
    }
}
