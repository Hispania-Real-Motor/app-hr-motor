<?php

namespace App\Filament\Pages;

use App\Models\EnreachErrorEvent;
use BackedEnum;
use Filament\Pages\Dashboard;
use Filament\Pages\Page;
use Illuminate\Support\Facades\Log;

class EnreachErrorsPage extends Page
{
    public const ERROR_TYPE_COMMERCIAL_PHONE_NOT_FOUND = EnreachErrorEvent::TYPE_COMMERCIAL_PHONE_NOT_FOUND;

    protected static string|BackedEnum|null $navigationIcon = 'heroicon-o-exclamation-triangle';
    protected static ?string $navigationLabel = 'Errores de Enreach';
    protected static ?string $title = 'Errores de Enreach';
    protected static ?string $slug = 'enreach-errors';
    protected static string|\UnitEnum|null $navigationGroup = 'Errores';
    protected static ?int $navigationSort = 11;
    protected static ?string $breadcrumb = 'Errores de Enreach';
    protected string $view = 'filament.pages.enreach-errors';

    public static function canAccess(): bool
    {
        return app_user_has_admin_permission(auth()->user(), 'enreach-errors.view');
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
            self::getUrl() => 'Enreach',
        ];
    }

    public function integrationIsConnected(): bool
    {
        return config('services.enreach.integration_status', 'pending') === 'connected';
    }

    public function errorTypes(): array
    {
        return [[
            'key' => self::ERROR_TYPE_COMMERCIAL_PHONE_NOT_FOUND,
            'label' => 'Teléfono no encontrado en Salesforce',
            'count' => $this->errorTypeCount(),
            'url' => EnreachErrorPhonesPage::getUrl(['type' => self::ERROR_TYPE_COMMERCIAL_PHONE_NOT_FOUND]),
        ]];
    }

    private function errorTypeCount(): int|string
    {
        if (! $this->integrationIsConnected()) {
            return 'Pendiente de integración';
        }

        try {
            return EnreachErrorEvent::query()
                ->where('error_type', self::ERROR_TYPE_COMMERCIAL_PHONE_NOT_FOUND)
                ->whereNotNull('phone_normalized')
                ->distinct()
                ->count('phone_normalized');
        } catch (\Throwable $exception) {
            report($exception);
            Log::error('No se pudo contar los teléfonos afectados por errores de Enreach.', [
                'error_type' => self::ERROR_TYPE_COMMERCIAL_PHONE_NOT_FOUND,
                'exception' => $exception,
            ]);

            return 'N/D';
        }
    }
}
