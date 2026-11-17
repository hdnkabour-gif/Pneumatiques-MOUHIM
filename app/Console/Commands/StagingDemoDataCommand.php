<?php

namespace App\Console\Commands;

use App\Actions\Staging\CreateStagingDemoDataAction;
use App\Enums\RoleName;
use App\Models\User;
use App\Support\Money;
use App\Support\StagingGuard;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Artisan;
use RuntimeException;

use function Laravel\Prompts\confirm;

#[Signature('app:staging-demo-data {--owner= : E-mail of the owner account recorded as the actor (default: the first active owner)} {--force : Skip the confirmation}')]
#[Description('STAGING ONLY: create a fictitious working day on the three sites (refused anywhere else)')]
class StagingDemoDataCommand extends Command
{
    public function handle(CreateStagingDemoDataAction $demo): int
    {
        if ($refusal = StagingGuard::refusal()) {
            $this->error("Refused: {$refusal} Demo data is only created in the PNEU MOUHIM Staging project.");

            return self::FAILURE;
        }

        $owner = User::role(RoleName::Owner->value)->where('is_active', true)
            ->when($this->option('owner'), fn ($q, $email) => $q->where('email', $email))
            ->orderBy('id')->first();
        if (! $owner) {
            $this->error('No active owner account found.');

            return self::FAILURE;
        }

        if (! $this->option('force') && ! confirm('Add a fictitious working day (marked "démo") to this Staging database?', false)) {
            return self::FAILURE;
        }

        try {
            $result = $demo->execute($owner);
        } catch (RuntimeException $e) {
            $this->error($e->getMessage());

            return self::FAILURE;
        }

        $this->info("Demo day {$result['batch']} created (actor: {$owner->email}).");
        $this->table(['Sale', 'Number', 'Total TTC'], collect($result['sales'])
            ->map(fn ($sale, $kind) => [$kind, $sale[0], Money::format($sale[1])])->values()->all());
        $this->line('Purchase order: '.$result['purchase_order']);
        $this->line('Transfers received: '.implode(', ', $result['transfers']['received']).' — in transit: '.$result['transfers']['in_transit']);
        $this->line('Company account outstanding: '.Money::format($result['company_outstanding_cents']));
        $this->line('Supplier balance: '.Money::format($result['supplier_balance_cents']));
        $this->line('M01 till closed: expected '.Money::format($result['m01_till']['expected_cents']).', difference '.Money::format($result['m01_till']['difference_cents']));
        $this->line('Photos: '.implode(', ', $result['photos']));

        $verified = Artisan::call('stock:verify');
        $this->line(trim(Artisan::output()));

        return $verified === 0 ? self::SUCCESS : self::FAILURE;
    }
}
