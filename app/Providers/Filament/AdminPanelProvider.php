<?php

namespace App\Providers\Filament;

use App\Enums\Locale;
use App\Filament\Pages\Auth\EditProfile;
use App\Http\Middleware\EnsureOwnerTwoFactor;
use App\Http\Middleware\SetLocale;
use App\Services\SettingsService;
use Filament\Actions\Action;
use Filament\Auth\MultiFactor\App\AppAuthentication;
use Filament\Http\Middleware\Authenticate;
use Filament\Http\Middleware\AuthenticateSession;
use Filament\Http\Middleware\DisableBladeIconComponents;
use Filament\Http\Middleware\DispatchServingFilamentEvent;
use Filament\Navigation\NavigationGroup;
use Filament\Pages\Dashboard;
use Filament\Panel;
use Filament\PanelProvider;
use Filament\Support\Colors\Color;
use Filament\Support\Icons\Heroicon;
use Filament\View\PanelsRenderHook;
use Filament\Widgets\AccountWidget;
use Illuminate\Cookie\Middleware\AddQueuedCookiesToResponse;
use Illuminate\Cookie\Middleware\EncryptCookies;
use Illuminate\Foundation\Http\Middleware\PreventRequestForgery;
use Illuminate\Routing\Middleware\SubstituteBindings;
use Illuminate\Session\Middleware\StartSession;
use Illuminate\Support\HtmlString;
use Illuminate\View\Middleware\ShareErrorsFromSession;

class AdminPanelProvider extends PanelProvider
{
    public function panel(Panel $panel): Panel
    {
        return $panel
            ->default()
            ->id('admin')
            ->path('admin')
            // Built-in login is rate limited (5 attempts / minute) and refuses inactive users.
            ->login()
            ->profile(EditProfile::class, isSimple: false)
            // Google Authenticator / Microsoft Authenticator…, with recovery codes (§19, D-47).
            ->multiFactorAuthentication([
                AppAuthentication::make()->recoverable()->brandName('PNEUMATIQUE MOUHIM'),
            ])
            // The app carries the company's name and logo (owner's request, 10 Nov 2026).
            ->brandName(fn () => rescue(fn () => app(SettingsService::class)->get('company.name'), null, false) ?: __('app.name'))
            ->brandLogo('/brand/logo.png')
            ->darkModeBrandLogo('/brand/logo-dark.png')
            ->brandLogoHeight('2.6rem')
            ->favicon('/brand/favicon.png')
            ->colors([
                'primary' => Color::Amber,
            ])
            ->sidebarCollapsibleOnDesktop()
            ->databaseNotifications()
            ->databaseNotificationsPolling('30s')
            ->navigationGroups([
                NavigationGroup::make(fn () => __('app.navigation.sales')),
                NavigationGroup::make(fn () => __('app.navigation.catalog')),
                NavigationGroup::make(fn () => __('app.navigation.stock')),
                NavigationGroup::make(fn () => __('app.navigation.purchases')),
                NavigationGroup::make(fn () => __('app.navigation.vehicles')),
                NavigationGroup::make(fn () => __('app.navigation.administration')),
                NavigationGroup::make(fn () => __('app.navigation.settings')),
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
            ->userMenuItems(array_map(
                fn (Locale $locale) => Action::make('locale_'.$locale->value)
                    ->label($locale->label())
                    ->icon(Heroicon::OutlinedLanguage)
                    ->url(fn () => route('locale.switch', $locale->value))
                    ->visible(fn () => auth()->user()?->locale !== $locale),
                Locale::cases(),
            ))
            ->renderHook(PanelsRenderHook::AUTH_LOGIN_FORM_AFTER, fn () => view('auth.locale-switcher'))
            // Installable on the phone (PWA, §16.1).
            ->renderHook(PanelsRenderHook::HEAD_END, fn () => new HtmlString(
                '<link rel="manifest" href="/manifest.webmanifest"><meta name="theme-color" content="#F5B800">'
                .'<link rel="apple-touch-icon" href="/pwa/apple-touch-icon.png"><meta name="apple-mobile-web-app-title" content="PNEUMATIQUE MOUHIM">'
                .'<script>if ("serviceWorker" in navigator) { navigator.serviceWorker.register("/sw.js"); }</script>'
                .view('push-script')->render()))
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
            // Only the language must also apply to background Livewire requests.
            // Re-running the session/cookie middleware there logs users out.
            ->middleware([SetLocale::class], isPersistent: true)
            ->authMiddleware([
                Authenticate::class,
                EnsureOwnerTwoFactor::class,
            ]);
    }
}
