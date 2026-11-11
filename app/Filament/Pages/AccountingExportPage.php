<?php

namespace App\Filament\Pages;

use App\Enums\Permission;
use App\Filament\Support\PageHub;
use App\Services\Reports\AccountingExport;
use BackedEnum;
use Carbon\CarbonImmutable;
use Filament\Actions\Action;
use Filament\Forms\Components\Select;
use Filament\Pages\Page;
use Filament\Panel;
use Filament\Support\Icons\Heroicon;

/** Monthly workbook for the accountant (§16.3), owner only. */
class AccountingExportPage extends Page
{
    public static function shouldRegisterNavigation(): bool
    {
        return PageHub::inMenu(static::class) && parent::shouldRegisterNavigation();
    }

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedArrowDownTray;

    protected static ?int $navigationSort = 92;

    protected string $view = 'filament.pages.accounting-export';

    public static function canAccess(): bool
    {
        return auth()->user()?->can(Permission::ApprovalsDecide->value) ?? false;
    }

    public static function getSlug(?Panel $panel = null): string
    {
        return 'export-comptable';
    }

    public static function getNavigationGroup(): ?string
    {
        return __('app.navigation.settings');
    }

    public static function getNavigationLabel(): string
    {
        return __('reports.export.title');
    }

    public function getTitle(): string
    {
        return __('reports.export.title');
    }

    protected function getHeaderActions(): array
    {
        return [
            Action::make('download')->label(__('reports.export.download'))->icon(Heroicon::OutlinedArrowDownTray)
                ->schema([
                    Select::make('month')->label(__('reports.export.month'))->required()
                        ->default(now(config('mouhim.timezone'))->format('Y-m'))
                        ->options(collect(range(0, 23))->mapWithKeys(fn ($i) => [($m = now(config('mouhim.timezone'))->startOfMonth()->subMonths($i))->format('Y-m') => $m->format('m/Y')])->all()),
                ])
                ->action(function (array $data) {
                    $month = CarbonImmutable::createFromFormat('Y-m-d', $data['month'].'-01', config('mouhim.timezone'));
                    $path = storage_path('app/export-'.uniqid().'.xlsx');
                    app(AccountingExport::class)->write($month, $path);
                    activity('reports')->causedBy(auth()->user())->event('accounting_export')->withProperties(['month' => $data['month']])->log('accounting_export');

                    return response()->download($path, 'PNEU-MOUHIM-comptabilite-'.$data['month'].'.xlsx')->deleteFileAfterSend();
                }),
        ];
    }
}
