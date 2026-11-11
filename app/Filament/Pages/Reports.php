<?php

namespace App\Filament\Pages;

use App\Enums\Permission;
use App\Enums\RoleName;
use App\Filament\Support\Fields;
use App\Filament\Support\PageHub;
use App\Models\Site;
use App\Services\Reports\SalesReport;
use App\Support\Money;
use BackedEnum;
use Carbon\CarbonImmutable;
use Filament\Actions\Action;
use Filament\Pages\Page;
use Filament\Panel;
use Filament\Support\Icons\Heroicon;
use Illuminate\Support\Collection;
use OpenSpout\Common\Entity\Row;
use OpenSpout\Writer\XLSX\Writer;

/** Reports (§16.3): one page, a period, a site — owner and site managers. */
class Reports extends Page
{
    public static function shouldRegisterNavigation(): bool
    {
        return PageHub::inMenu(static::class) && parent::shouldRegisterNavigation();
    }

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedChartBar;

    protected static ?int $navigationSort = -8;

    protected string $view = 'filament.pages.reports';

    public string $from = '';

    public string $to = '';

    public ?int $siteId = null;

    public static function canAccess(): bool
    {
        $user = auth()->user();

        return $user && ($user->can(Permission::ApprovalsDecide->value) || $user->hasRole(RoleName::SiteManager->value));
    }

    public static function getSlug(?Panel $panel = null): string
    {
        return 'rapports';
    }

    public static function getNavigationLabel(): string
    {
        return __('reports.title');
    }

    public function getTitle(): string
    {
        return __('reports.title');
    }

    public function mount(): void
    {
        $now = CarbonImmutable::now(config('mouhim.timezone'));
        $this->from = $now->startOfMonth()->toDateString();
        $this->to = $now->toDateString();
    }

    public function period(string $p): void
    {
        $now = CarbonImmutable::now(config('mouhim.timezone'));
        [$this->from, $this->to] = match ($p) {
            'today' => [$now->toDateString(), $now->toDateString()],
            'week' => [$now->startOfWeek()->toDateString(), $now->toDateString()],
            'last_month' => [$now->subMonthNoOverflow()->startOfMonth()->toDateString(), $now->subMonthNoOverflow()->endOfMonth()->toDateString()],
            'year' => [$now->startOfYear()->toDateString(), $now->toDateString()],
            default => [$now->startOfMonth()->toDateString(), $now->toDateString()],
        };
    }

    public function report(): SalesReport
    {
        $tz = config('mouhim.timezone');
        $from = rescue(fn () => CarbonImmutable::parse($this->from, $tz), CarbonImmutable::now($tz)->startOfMonth(), false);
        $to = rescue(fn () => CarbonImmutable::parse($this->to, $tz), CarbonImmutable::now($tz), false);
        $site = $this->siteId && auth()->user()->canAccessSite($this->siteId) ? $this->siteId : null;

        return new SalesReport($from, $to, $site, auth()->user());
    }

    public function sites(): Collection
    {
        return Site::query()->visibleTo(auth()->user())->where('is_active', true)->orderBy('code')->get();
    }

    public function canSeeCosts(): bool
    {
        return Fields::canSeeCosts();
    }

    public function money(int|string|null $cents): string
    {
        return Money::format((int) $cents);
    }

    protected function getHeaderActions(): array
    {
        return [
            Action::make('excel')->label(__('reports.excel'))->icon(Heroicon::OutlinedArrowDownTray)->color('gray')
                ->action(function () {
                    $r = $this->report();
                    $path = storage_path('app/report-'.uniqid().'.xlsx');
                    $w = new Writer;
                    $w->openToFile($path);
                    $costs = $this->canSeeCosts();
                    $first = true;
                    foreach (['site', 'seller', 'category', 'brand'] as $by) {
                        if (! $first) {
                            $w->addNewSheetAndMakeItCurrent();
                        }
                        $first = false;
                        $w->getCurrentSheet()->setName(__('reports.by.'.$by));
                        $w->addRow(Row::fromValues(array_filter([__('reports.cols.label'), __('reports.cols.docs'), 'TTC', 'HT', $costs ? __('reports.cols.margin') : null])));
                        foreach ($r->by($by) as $row) {
                            $w->addRow(Row::fromValues(array_values(array_filter([$row->label, (int) $row->docs, $row->ttc / 100, $row->ht / 100,
                                $costs ? ($row->incomplete ? __('today.margin_incomplete') : $row->margin / 100) : '__skip'], fn ($v) => $v !== '__skip'))));
                        }
                    }
                    $w->addNewSheetAndMakeItCurrent();
                    $w->getCurrentSheet()->setName(__('reports.aged'));
                    $w->addRow(Row::fromValues([__('reports.cols.customer'), '0-30', '31-60', '61-90', '> 90', 'Total']));
                    foreach ($r->agedBalance() as $a) {
                        $w->addRow(Row::fromValues([$a->customer, $a->b0 / 100, $a->b30 / 100, $a->b60 / 100, $a->b90 / 100, $a->total / 100]));
                    }
                    $w->close();

                    return response()->download($path, 'rapport-'.$this->from.'-'.$this->to.'.xlsx')->deleteFileAfterSend();
                }),
        ];
    }
}
