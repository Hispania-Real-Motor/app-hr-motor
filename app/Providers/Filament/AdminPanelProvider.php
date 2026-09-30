<?php

namespace App\Providers\Filament;

use Filament\Http\Middleware\Authenticate;
use Filament\Http\Middleware\AuthenticateSession;
use Filament\Http\Middleware\DisableBladeIconComponents;
use Filament\Http\Middleware\DispatchServingFilamentEvent;
use Filament\Enums\ThemeMode;
use Filament\Pages\Dashboard;
use Filament\Panel;
use Filament\PanelProvider;
use Filament\Support\Colors\Color;
use Filament\View\PanelsRenderHook;
use Filament\Widgets\AccountWidget;
use Illuminate\Cookie\Middleware\AddQueuedCookiesToResponse;
use Illuminate\Cookie\Middleware\EncryptCookies;
use Illuminate\Foundation\Http\Middleware\VerifyCsrfToken;
use Illuminate\Routing\Middleware\SubstituteBindings;
use Illuminate\Session\Middleware\StartSession;
use Illuminate\View\Middleware\ShareErrorsFromSession;

class AdminPanelProvider extends PanelProvider
{
    public function panel(Panel $panel): Panel
    {
        return $panel
            ->default()
            ->id('backoffice')
            ->path('backoffice')
            ->viteTheme('resources/css/filament/backoffice/theme.css')
            ->login()
            ->defaultThemeMode(ThemeMode::Dark)
            ->sidebarCollapsibleOnDesktop()
            ->collapsibleNavigationGroups()
            ->navigationGroups([
                'Administración',
                'Contenido',
            ])
            ->brandLogo(fn (): string => request()->isSecure()
                ? secure_asset('images/logo-horizontal.svg')
                : asset('images/logo-horizontal.svg'))
            ->darkModeBrandLogo(fn (): string => request()->isSecure()
                ? secure_asset('images/logo-hr-white.svg')
                : asset('images/logo-hr-white.svg'))
            ->favicon(fn (): string => request()->isSecure()
                ? secure_asset('images/favicon-black.png')
                : asset('images/favicon-black.png'))
            ->brandLogoHeight('2rem')
            ->renderHook(PanelsRenderHook::USER_MENU_BEFORE, fn (): string => view('filament.backoffice.home-link')->render())
            ->renderHook(PanelsRenderHook::USER_MENU_BEFORE, fn (): string => view('filament.backoffice.theme-toggle')->render())
            ->colors([
                'primary' => Color::hex('#E51A2E'),
            ])
            ->discoverResources(in: app_path('Filament/Resources'), for: 'App\Filament\Resources')
            ->discoverPages(in: app_path('Filament/Pages'), for: 'App\Filament\Pages')
            ->pages([
                Dashboard::class,
            ])
            ->discoverWidgets(in: app_path('Filament/Widgets'), for: 'App\Filament\Widgets')
            ->widgets([
                AccountWidget::class,
            ])
            ->middleware([
                EncryptCookies::class,
                AddQueuedCookiesToResponse::class,
                StartSession::class,
                AuthenticateSession::class,
                ShareErrorsFromSession::class,
                VerifyCsrfToken::class,
                SubstituteBindings::class,
                DisableBladeIconComponents::class,
                DispatchServingFilamentEvent::class,
            ])
            ->authMiddleware([
                Authenticate::class,
            ]);
    }
}
