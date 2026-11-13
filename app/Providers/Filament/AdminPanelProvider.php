<?php

namespace App\Providers\Filament;

use App\Filament\Pages\StaffLogin;
use Filament\Enums\ThemeMode;
use Filament\Http\Middleware\Authenticate;
use Filament\Http\Middleware\AuthenticateSession;
use Filament\Http\Middleware\DisableBladeIconComponents;
use Filament\Http\Middleware\DispatchServingFilamentEvent;
use Filament\Pages\Dashboard;
use Filament\Panel;
use Filament\PanelProvider;
use Filament\Support\Colors\Color;
use Filament\View\PanelsRenderHook;
use Illuminate\Contracts\View\View;
use Illuminate\Cookie\Middleware\AddQueuedCookiesToResponse;
use Illuminate\Cookie\Middleware\EncryptCookies;
use Illuminate\Foundation\Http\Middleware\PreventRequestForgery;
use Illuminate\Foundation\Vite;
use Illuminate\Routing\Middleware\SubstituteBindings;
use Illuminate\Session\Middleware\StartSession;
use Illuminate\View\Middleware\ShareErrorsFromSession;

class AdminPanelProvider extends PanelProvider
{
    public function panel(Panel $panel): Panel
    {
        return $panel
            ->default()
            ->databaseTransactions()
            ->brandName('CueLab')
            ->brandLogo(fn (): View => view('components.cuelab-wordmark'))
            ->brandLogoHeight('3.5rem')
            ->renderHook(PanelsRenderHook::BODY_START, fn (): View => view('components.cuelab-wordmark-filter'))
            ->renderHook(PanelsRenderHook::STYLES_AFTER, fn (): View => view('components.theme-preference'))
            ->renderHook(PanelsRenderHook::HEAD_END, fn () => app(Vite::class)->fonts(['geist', 'barlow-condensed']), scopes: StaffLogin::class)
            ->renderHook(PanelsRenderHook::SCRIPTS_AFTER, fn () => app(Vite::class)(['resources/js/staff-time-picker.js']))
            ->renderHook(PanelsRenderHook::AUTH_LOGIN_FORM_AFTER, fn (): View => view('filament.staff-login-home'), scopes: StaffLogin::class)
            ->defaultThemeMode(ThemeMode::System)
            ->sidebarCollapsibleOnDesktop()
            ->sidebarWidth('15rem')
            ->maxContentWidth('full')
            ->navigationGroups(['Operations', 'Venue', 'Administration'])
            ->id('admin')
            ->path('admin')
            ->viteTheme('resources/css/filament/admin/theme.css')
            ->login(StaffLogin::class)
            ->colors([
                'primary' => Color::hex('#a92032'),
                'gray' => Color::Stone,
            ])
            ->discoverResources(in: app_path('Filament/Resources'), for: 'App\Filament\Resources')
            ->discoverPages(in: app_path('Filament/Pages'), for: 'App\Filament\Pages')
            ->pages([
                Dashboard::class,
            ])
            ->discoverWidgets(in: app_path('Filament/Widgets'), for: 'App\Filament\Widgets')
            ->widgets([

            ])
            ->middleware([
                EncryptCookies::class,
                AddQueuedCookiesToResponse::class,
                StartSession::class,
                AuthenticateSession::class,
                ShareErrorsFromSession::class,
                PreventRequestForgery::class,
                SubstituteBindings::class,
                DisableBladeIconComponents::class,
                DispatchServingFilamentEvent::class,
            ])
            ->authMiddleware([
                Authenticate::class,
            ]);
    }
}
