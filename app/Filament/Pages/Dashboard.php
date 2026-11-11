<?php

namespace App\Filament\Pages;

use App\Enums\Permission;
use App\Filament\Resources\StockLevels\StockLevelResource;
use Filament\Pages\Dashboard as BaseDashboard;

/**
 * Simplicity (§5): nobody starts on an empty dashboard. Each role lands on
 * the page it works on — owner and managers on « Aujourd'hui », sellers on
 * « Nouvelle vente », storekeepers on the stock. Users with none of these
 * (e.g. a technician) keep this page.
 */
class Dashboard extends BaseDashboard
{
    public static function home(): ?string
    {
        $user = auth()->user();

        return match (true) {
            $user === null => null,
            Today::canAccess() => Today::getUrl(),
            SaleScreen::canAccess() => SaleScreen::getUrl(),
            $user->can(Permission::StockView->value) && StockLevelResource::canViewAny() => StockLevelResource::getUrl(),
            default => null,
        };
    }

    public function mount(): void
    {
        if ($url = self::home()) {
            $this->redirect($url);
        }
    }

    public static function shouldRegisterNavigation(): bool
    {
        return self::home() === null;
    }
}
