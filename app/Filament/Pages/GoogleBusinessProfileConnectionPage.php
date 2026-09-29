<?php

namespace App\Filament\Pages;

use App\Services\GoogleBusinessProfileReviewService;
use BackedEnum;
use Filament\Pages\Page;

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

    public static function canAccess(): bool
    {
        return app_user_has_admin_permission(auth()->user(), 'reviews.google.manage');
    }

    public function mount(GoogleBusinessProfileReviewService $service): void
    {
        abort_unless(static::canAccess(), 403);

        $connection = $service->getConnection();
        $this->connected = $connection !== null;
        $this->accountName = $connection?->account_name;
        $this->lastSyncedAt = $connection?->last_synced_at?->format('d/m/Y H:i');
    }
}
