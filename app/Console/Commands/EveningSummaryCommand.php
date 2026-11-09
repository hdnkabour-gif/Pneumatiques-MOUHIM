<?php

namespace App\Console\Commands;

use App\Enums\Permission;
use App\Models\Site;
use App\Models\User;
use App\Services\Reports\DailyFigures;
use App\Support\Money;
use Carbon\CarbonImmutable;
use Filament\Notifications\Notification;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Mail;
use Throwable;

/** Evening summary to the owner (§16.2): in-app notification + email. */
class EveningSummaryCommand extends Command
{
    protected $signature = 'reports:evening-summary';

    protected $description = 'Send the day summary (sales, money, credit, expenses per site) to the owner';

    public function handle(DailyFigures $figures): int
    {
        $day = CarbonImmutable::now(config('mouhim.timezone'))->startOfDay();
        $owners = User::query()->where('is_active', true)->get()->filter(fn (User $u) => $u->can(Permission::ApprovalsDecide->value));

        foreach ($owners as $owner) {
            $previous = app()->getLocale();
            app()->setLocale($owner->locale?->value ?? config('mouhim.default_locale'));
            $lines = Site::query()->where('is_active', true)->orderBy('code')->get()->map(function (Site $site) use ($figures, $day) {
                $f = $figures->forDay($site, $day);

                return __('today.summary.line', ['site' => $site->code] + array_map(fn ($v) => Money::format((int) $v),
                    array_intersect_key($f, array_flip(['sales', 'cash', 'card', 'cheque', 'credit', 'expenses']))));
            })->all();
            $total = $figures->forDay(null, $day);
            $alerts = $figures->alerts($day);
            $title = __('today.summary.title', ['date' => $day->format('d/m/Y')]);
            $body = implode("\n", [...$lines, __('today.summary.total', ['sales' => Money::format($total['sales']), 'count' => $total['count']]), ...$alerts]);

            Notification::make()->title($title)->body(nl2br(e($body)))->info()->sendToDatabase($owner);
            try {
                Mail::raw($body, fn ($m) => $m->to($owner->email)->subject($title));
            } catch (Throwable $e) {
                $this->warn('Email not sent: '.$e->getMessage()); // the in-app notification is enough
            }
            app()->setLocale($previous);
        }

        return self::SUCCESS;
    }
}
