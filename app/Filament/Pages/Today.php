<?php

namespace App\Filament\Pages;

use App\Enums\Permission;
use App\Enums\RoleName;
use App\Filament\Support\Fields;
use App\Filament\Support\PageHub;
use App\Models\Site;
use App\Services\Push\PushNotifier;
use App\Services\Reports\DailyFigures;
use App\Support\Decimal;
use App\Support\Money;
use BackedEnum;
use Carbon\CarbonImmutable;
use Filament\Actions\Action;
use Filament\Pages\Page;
use Filament\Panel;
use Filament\Support\Icons\Heroicon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Spatie\Activitylog\Models\Activity;

/** « Aujourd'hui » (§16.1): the owner's first page on the phone. */
class Today extends Page
{
    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedSun;

    protected static ?int $navigationSort = -11;

    protected string $view = 'filament.pages.today';

    public static function canAccess(): bool
    {
        $user = auth()->user();

        return $user && ($user->can(Permission::ApprovalsDecide->value) || $user->hasRole(RoleName::SiteManager->value));
    }

    public static function getSlug(?Panel $panel = null): string
    {
        return 'aujourdhui';
    }

    public static function getNavigationLabel(): string
    {
        return __('today.title');
    }

    public function getTitle(): string
    {
        return __('today.title');
    }

    public function day(): CarbonImmutable
    {
        return CarbonImmutable::now(config('mouhim.timezone'))->startOfDay();
    }

    public function sites(): Collection
    {
        return Site::query()->visibleTo(auth()->user())->where('is_active', true)->orderBy('code')->get();
    }

    public function figures(?Site $site, ?CarbonImmutable $day = null): array
    {
        return app(DailyFigures::class)->forDay($site, $day ?? $this->day());
    }

    /** Totals over the sites this user sees (all sites for the owner). */
    public function total(?CarbonImmutable $day = null): array
    {
        if (auth()->user()->canSeeAllSites()) {
            return $this->figures(null, $day);
        }
        $rows = $this->sites()->map(fn (Site $s) => $this->figures($s, $day));
        $sum = fn ($k) => (int) $rows->sum($k);

        return ['sales' => $sum('sales'), 'count' => $sum('count'), 'cash' => $sum('cash'), 'card' => $sum('card'), 'cheque' => $sum('cheque'),
            'credit' => $sum('credit'), 'expenses' => $sum('expenses'), 'credit_notes' => $sum('credit_notes'),
            'margin' => $rows->contains(fn ($r) => $r['margin'] === null) ? null : $sum('margin')];
    }

    public function alerts(): array
    {
        return auth()->user()->can(Permission::ApprovalsDecide->value) ? app(DailyFigures::class)->alerts($this->day()) : [];
    }

    public function month(Site $site): array
    {
        $figures = app(DailyFigures::class);

        return ['done' => $figures->monthSales($site, $this->day()), 'target' => $figures->target($site, $this->day())];
    }

    public function feed(): Collection
    {
        $user = auth()->user();

        return Activity::query()->with('causer')->whereIn('event', ['sale_confirmed', 'quote_issued', 'credit_note', 'cash_closed', 'transfer_sent', 'transfer_received', 'payment_received', 'expense'])
            ->when(! $user->canSeeAllSites(), fn ($q) => $q->whereIn('site_id', $user->siteIds()))
            ->latest('id')->limit(8)->get();
    }

    public function canSeeMargin(): bool
    {
        return Fields::canSeeCosts();
    }

    public function money(?int $cents): string
    {
        return $cents === null ? '—' : Money::format($cents);
    }

    protected function getHeaderActions(): array
    {
        return [...PageHub::actions(static::class),
            Action::make('push')->label(__('push.enable'))->icon(Heroicon::OutlinedBellAlert)->color('gray')
                ->visible(fn () => app(PushNotifier::class)->enabled())
                ->alpineClickHandler('window.mouhimEnablePush && window.mouhimEnablePush()'),
            Action::make('targets')->label(__('today.set_targets'))->icon(Heroicon::OutlinedFlag)->color('gray')
                ->visible(fn () => auth()->user()->can(Permission::ApprovalsDecide->value))
                ->schema(fn () => $this->sites()->map(fn (Site $s) => Fields::money('target_'.$s->id, $s->name)
                    ->default(fn () => ($t = app(DailyFigures::class)->target($s, $this->day())) ? Decimal::fromCents($t) : null))->all())
                ->action(function (array $data) {
                    foreach ($this->sites() as $s) {
                        if (filled($data['target_'.$s->id] ?? null)) {
                            DB::table('site_objectives')->updateOrInsert(['site_id' => $s->id, 'month' => $this->day()->format('Y-m')],
                                ['target_cents' => (int) $data['target_'.$s->id], 'set_by' => auth()->id(), 'updated_at' => now(), 'created_at' => now()]);
                        }
                    }
                }),
        ];
    }
}
