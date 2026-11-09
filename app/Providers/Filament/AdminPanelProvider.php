<?php

namespace App\Providers\Filament;

use App\Enums\Locale;
use App\Filament\Pages\Auth\EditProfile;
use App\Http\Middleware\EnsureOwnerTwoFactor;
use App\Http\Middleware\SetLocale;
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
                AppAuthentication::make()->recoverable()->brandName('PNEU MOUHIM'),
            ])
            ->brandName(fn () => __('app.name'))
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
                '<link rel="manifest" href="/manifest.webmanifest"><meta name="theme-color" content="#e07000">'
                .'<link rel="apple-touch-icon" href="/pwa/icon-192.png">'
                .'<script>if ("serviceWorker" in navigator) { navigator.serviceWorker.register("/sw.js"); }</script>'))
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
