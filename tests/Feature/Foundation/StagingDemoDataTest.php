<?php

use App\Actions\Staging\CreateStagingDemoDataAction;
use App\Actions\UsedTyres\UsedTyreActions;
use App\Enums\UsedTyreOrigin;
use App\Enums\UsedTyreStatus;
use App\Models\Customer;
use App\Models\Product;
use App\Models\Sale;
use App\Models\Site;
use App\Models\StockLevel;
use App\Models\Supplier;
use App\Models\Transfer;
use App\Models\UsedTyre;
use App\Models\User;
use App\Services\Money\CashRegister;
use App\Services\Stock\StockVerifier;
use App\Support\StagingGuard;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;

/*
| Demo data for the isolated Staging project only. The guard reads the variables Railway sets
| in the process (project ID + environment name), never APP_ENV (Staging runs in production mode).
*/

function railwayEnvironment(?string $project, ?string $environment): void
{
    foreach (['RAILWAY_PROJECT_ID' => $project, 'RAILWAY_ENVIRONMENT_NAME' => $environment] as $name => $value) {
        putenv($value === null ? $name : "{$name}={$value}");
    }
}

beforeEach(function () {
    Storage::fake('public');
});

afterEach(function () {
    railwayEnvironment(null, null);
});

it('refuses to run anywhere but the Staging project and environment, whatever APP_ENV says', function (?string $project, ?string $environment, string $reason) {
    app()->detectEnvironment(fn () => 'local');
    owner();
    railwayEnvironment($project, $environment);

    $this->artisan('app:staging-demo-data', ['--force' => true])
        ->expectsOutputToContain($reason)
        ->assertFailed();

    expect(Product::query()->where('name', 'like', '%(démo)%')->count())->toBe(0)
        ->and(fn () => app(CreateStagingDemoDataAction::class)->execute(owner()))->toThrow(RuntimeException::class, 'Demo data refused');
})->with([
    'no Railway variables (local machine, CI)' => [null, null, 'RAILWAY_PROJECT_ID is missing'],
    'another Railway project (the shop)' => ['11111111-2222-3333-4444-555555555555', 'production', 'RAILWAY_PROJECT_ID differs'],
    'Staging project, other environment' => [StagingGuard::PROJECT_ID, 'production', 'is not the "staging" environment'],
]);

it('creates a complete fictitious day on the three sites, with exact figures', function () {
    app()->detectEnvironment(fn () => 'production'); // as on Staging
    $owner = owner();
    railwayEnvironment(StagingGuard::PROJECT_ID, StagingGuard::ENVIRONMENT);

    $this->artisan('app:staging-demo-data', ['--force' => true])
        ->expectsOutputToContain('created (actor: '.$owner->email.')')
        ->assertSuccessful();

    $product = fn (string $name) => Product::query()->where('name', $name)->sole();
    $qty = fn (Product $p, string $site) => (int) StockLevel::query()->where(['product_id' => $p->id, 'site_id' => site($site)->id])->value('quantity');
    [$a, $b, $oil] = [$product('Pneu 205/55 R16 (démo)'), $product('Pneu 195/65 R15 (démo)'), $product('Huile 5W30 bidon 5 L (démo)')];

    // Stock: received 40 / 20 / 30 at the depot, sent 12+8 (+4 in transit) / 6+4 / 10, sold 4+4 / 2 / 1.
    expect([$qty($a, 'DEP'), $qty($a, 'M01'), $qty($a, 'M02')])->toBe([16, 8, 4])
        ->and([$qty($b, 'DEP'), $qty($b, 'M01'), $qty($b, 'M02')])->toBe([10, 4, 4])
        ->and([$qty($oil, 'DEP'), $qty($oil, 'M01'), $qty($oil, 'M02')])->toBe([20, 9, 0])
        ->and(Transfer::query()->where('status', 'in_transit')->count())->toBe(1)
        ->and(app(StockVerifier::class)->differences())->toBe([]);

    // Sales and invoices: cash 4×800 + 250, card 2×650, credit 4×700 (pro price).
    $sales = Sale::query()->whereNotNull('number')->orderBy('id')->get();
    expect($sales->pluck('total_ttc_cents')->all())->toBe([345000, 130000, 280000])
        ->and($sales->pluck('number')->unique())->toHaveCount(3)
        ->and(DB::table('issued_invoice_documents')->whereIn('sale_id', $sales->pluck('id'))->count())->toBe(3);

    // Money: company 3 000 opening + 2 800 credit − 2 000 cheque; supplier 28 300 HT × 1.2 − 5 000 paid.
    expect(Customer::query()->where('name', 'like', 'Transports démo%')->sole()->outstandingCents())->toBe(380000)
        ->and(Supplier::query()->where('name', 'like', 'Fournisseur démo%')->sole()->balanceCents())->toBe(3396000 - 500000);

    // M01 till: float 500 + cash 3 450 − expense 30, counted exactly, closed.
    $day = app(CashRegister::class)->day(site('M01'));
    expect($day->isClosed())->toBeTrue()
        ->and($day->expected_cents)->toBe(392000)
        ->and($day->difference_cents)->toBe(0);

    // Photos stored where uploads go, and linked to their records.
    $used = UsedTyre::query()->sole();
    expect($used->status)->toBe(UsedTyreStatus::InStock)
        ->and(Storage::disk('public')->exists($used->photos[0]))->toBeTrue()
        ->and(Storage::disk('public')->exists(DB::table('expenses')->value('photo')))->toBeTrue();

    // Demo staff cannot log in: their passwords were random and never shown.
    expect(DB::table('users')->where('email', 'like', 'demo-%@example.invalid')->count())->toBe(4);

    // A second run the same day is refused (the M01 till is closed) and adds nothing.
    $this->artisan('app:staging-demo-data', ['--force' => true])
        ->expectsOutputToContain('already closed today')
        ->assertFailed();
    expect(Product::query()->where('name', 'like', '%(démo)%')->count())->toBe(3);
});

it('keeps nothing, database or photos, when a step fails half-way', function () {
    app()->detectEnvironment(fn () => 'production');
    $owner = owner();
    railwayEnvironment(StagingGuard::PROJECT_ID, StagingGuard::ENVIRONMENT);
    // Fails after staff, catalogue, purchases, transfers, sales and the first photo were created.
    app()->instance(UsedTyreActions::class, new class extends UsedTyreActions
    {
        public function __construct() {}

        public function register(array $data, Site $site, UsedTyreOrigin $origin, User $user, ?Model $originDocument = null): UsedTyre
        {
            throw new RuntimeException('Injected failure');
        }
    });

    expect(fn () => app(CreateStagingDemoDataAction::class)->execute($owner))->toThrow(RuntimeException::class, 'Injected failure');

    expect(Product::query()->where('name', 'like', '%(démo)%')->count())->toBe(0)
        ->and(Sale::query()->count())->toBe(0)
        ->and(DB::table('users')->where('email', 'like', 'demo-%')->count())->toBe(0)
        ->and(Storage::disk('public')->allFiles())->toBe([])
        ->and(app(CashRegister::class)->day(site('M01'))->isClosed())->toBeFalse();
});
