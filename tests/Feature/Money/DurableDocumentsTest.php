<?php

use App\Enums\RoleName;
use App\Exceptions\BusinessRuleException;
use App\Services\Money\CashRegister;
use App\Support\DocumentStorageGuard;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;

/*
 * #29 (P0 cutover): private documents must be on PERSISTENT storage. On Railway only the
 * mounted volume survives a redeploy, so the "documents" root must be inside
 * RAILWAY_VOLUME_MOUNT_PATH, and never inside the public web tree. When it is not, the
 * application fails closed: no receipt is moved off the public volume, no new receipt is
 * accepted, and the go-live audit fails.
 */
afterEach(function () {
    putenv('RAILWAY_VOLUME_MOUNT_PATH');
    putenv('RAILWAY_ENVIRONMENT_NAME');
});

function onRailway(string $mount): void
{
    putenv('RAILWAY_ENVIRONMENT_NAME=production');
    putenv('RAILWAY_VOLUME_MOUNT_PATH='.$mount);
}

it('accepts documents inside the mounted volume and outside the public tree', function () {
    onRailway(storage_path('app'));
    config(['filesystems.disks.documents.root' => storage_path('app/documents')]);
    expect(app(DocumentStorageGuard::class)->problem())->toBeNull();
});

it('refuses documents outside the mounted volume (today: volume only on app/public)', function () {
    onRailway(storage_path('app/public'));
    config(['filesystems.disks.documents.root' => storage_path('app/documents')]);
    expect(app(DocumentStorageGuard::class)->problem())->toContain('outside the persistent volume');
});

it('refuses a Railway service without a volume, and documents inside the public tree anywhere', function () {
    putenv('RAILWAY_ENVIRONMENT_NAME=production');
    config(['filesystems.disks.documents.root' => storage_path('app/documents')]);
    expect(app(DocumentStorageGuard::class)->problem())->toContain('no persistent volume');

    putenv('RAILWAY_ENVIRONMENT_NAME');
    config(['filesystems.disks.documents.root' => storage_path('app/public/private')]);
    expect(app(DocumentStorageGuard::class)->problem())->toContain('public web tree');
});

it('does not move receipts off the public volume when documents are not durable; public copies stay', function () {
    Storage::fake('public');
    Storage::fake('documents');
    Storage::disk('public')->put('expenses/keep-me.jpg', 'KEEP');
    app(CashRegister::class)->addExpense(site('M01'), ['category' => 'other', 'amount_cents' => 500,
        'description' => 'TEST', 'photo' => 'expenses/keep-me.jpg'], userWithRole(RoleName::SiteManager, 'M01'));
    onRailway('/nonexistent-volume');

    (require database_path('migrations/2026_11_23_090000_move_expense_receipts_to_private_disk.php'))->up();

    expect(Storage::disk('public')->get('expenses/keep-me.jpg'))->toBe('KEEP')
        ->and(Storage::disk('documents')->exists('expenses/keep-me.jpg'))->toBeFalse();

    // Self-healing: once the storage is durable, the boot command moves it, intact.
    putenv('RAILWAY_VOLUME_MOUNT_PATH='.storage_path('app'));
    config(['filesystems.disks.documents.root' => storage_path('app/documents')]);
    expect(Artisan::call('app:secure-receipts'))->toBe(0)
        ->and(Storage::disk('documents')->get('expenses/keep-me.jpg'))->toBe('KEEP')
        ->and(Storage::disk('public')->exists('expenses/keep-me.jpg'))->toBeFalse();
});

it('refuses a new receipt while documents are not durable, but still records an expense without one', function () {
    onRailway('/nonexistent-volume');
    $manager = userWithRole(RoleName::SiteManager, 'M01');
    expect(fn () => app(CashRegister::class)->addExpense(site('M01'), ['category' => 'other', 'amount_cents' => 500,
        'description' => 'TEST', 'photo' => 'expenses/new.jpg'], $manager))->toThrow(BusinessRuleException::class, __('cash.errors.documents_not_durable'));
    expect(app(CashRegister::class)->addExpense(site('M01'), ['category' => 'other', 'amount_cents' => 500,
        'description' => 'TEST sans photo'], $manager)->photo)->toBeNull();
});

it('fails the strict go-live audit while private documents are not durable', function () {
    owner();   // otherwise ready: three sites, an owner, references, no business rows
    onRailway('/nonexistent-volume');
    expect(Artisan::call('app:go-live-audit', ['--strict' => true]))->toBe(1)
        ->and(Artisan::output())->toContain('Private documents storage: FAIL')->toContain('GO_LIVE_NOT_READY');
    putenv('RAILWAY_VOLUME_MOUNT_PATH='.storage_path('app'));
    expect(Artisan::call('app:go-live-audit', ['--strict' => true]))->toBe(0)
        ->and(Artisan::output())->toContain('Private documents storage: PASS')->toContain('GO_LIVE_CLEAN_BASE_VERIFIED');
});

/*
 * #31 review (P1 x3): a receipt counts as secured only when the public copy is really gone;
 * a missing receipt or a failed public delete makes app:secure-receipts fail and alerts the
 * owners; a durable documents root that the backups do not cover is refused.
 */
function receiptExpense(string $path): void
{
    app(CashRegister::class)->addExpense(site('M01'), ['category' => 'other', 'amount_cents' => 500,
        'description' => 'TEST', 'photo' => $path], userWithRole(RoleName::SiteManager, 'M01'));
}

it('does not count a receipt as moved while its public copy could not be deleted; fails, alerts, then retries', function () {
    owner();
    Storage::fake('documents');
    $public = Storage::fake('public');
    $public->put('expenses/stuck.jpg', 'STUCK');
    receiptExpense('expenses/stuck.jpg');
    $failing = Mockery::mock($public)->makePartial();
    $failing->shouldReceive('delete')->andReturn(false);   // the public disk does not throw (throw=false)
    Storage::set('public', $failing);

    expect(Artisan::call('app:secure-receipts'))->not->toBe(0)
        ->and(Artisan::output())->toContain('SECURE_RECEIPTS status=FAIL')->toContain('delete_failed=1')
        ->and($public->get('expenses/stuck.jpg'))->toBe('STUCK')                 // still there, reported
        ->and(Storage::disk('documents')->get('expenses/stuck.jpg'))->toBe('STUCK') // verified private copy kept
        ->and(DB::table('notifications')->count())->toBeGreaterThan(0);

    Storage::set('public', $public);                                              // deletion works again: retry heals
    expect(Artisan::call('app:secure-receipts'))->toBe(0)
        ->and(Artisan::output())->toContain('SECURE_RECEIPTS status=OK')
        ->and($public->exists('expenses/stuck.jpg'))->toBeFalse();
});

it('fails and alerts the owners when a receipt is missing from both disks', function () {
    owner();
    Storage::fake('public');
    Storage::fake('documents');
    receiptExpense('expenses/vanished.jpg');

    expect(Artisan::call('app:secure-receipts'))->not->toBe(0)
        ->and(Artisan::output())->toContain('SECURE_RECEIPTS status=FAIL')->toContain('missing=1')
        ->and(DB::table('notifications')->count())->toBeGreaterThan(0);
});

it('refuses a durable documents root that the backups do not include', function () {
    onRailway(storage_path('app'));
    config(['filesystems.disks.documents.root' => storage_path('app/private_docs')]);
    expect(app(DocumentStorageGuard::class)->problem())->toContain('not included in the backups');

    config(['backup.backup.source.files.include' => [storage_path('app/public'), storage_path('app/private_docs')]]);
    expect(app(DocumentStorageGuard::class)->problem())->toBeNull();
});

/*
 * #31 review (P1): the server must not open while an old receipt is still reachable on the
 * public disk. `app:secure-receipts --boot` (run by the entrypoint under set -e) exits non-zero
 * in that case; the original stays where it is (never deleted before a verified private copy).
 */
it('refuses to boot while a receipt is still on the public disk because storage is not durable; keeps the original', function () {
    Storage::fake('public');
    Storage::fake('documents');
    Storage::disk('public')->put('expenses/legacy.jpg', 'LEGACY');
    receiptExpense('expenses/legacy.jpg');
    onRailway('/nonexistent-volume');

    expect(Artisan::call('app:secure-receipts', ['--boot' => true]))->toBe(1)
        ->and(Artisan::output())->toContain('status=SKIPPED')->toContain('exposed=1')->toContain('safe=no')
        ->and(Storage::disk('public')->get('expenses/legacy.jpg'))->toBe('LEGACY');
});

it('boots when nothing is exposed even if storage is not durable yet (new receipts are refused anyway)', function () {
    Storage::fake('public');
    Storage::fake('documents');
    onRailway('/nonexistent-volume');

    expect(Artisan::call('app:secure-receipts', ['--boot' => true]))->toBe(0)
        ->and(Artisan::output())->toContain('status=SKIPPED')->toContain('exposed=0')->toContain('safe=yes');
});

it('refuses to boot when a public copy could not be deleted, and boots once the receipt is secured', function () {
    owner();
    Storage::fake('documents');
    $public = Storage::fake('public');
    $public->put('expenses/stuck-boot.jpg', 'STUCK');
    receiptExpense('expenses/stuck-boot.jpg');
    $failing = Mockery::mock($public)->makePartial();
    $failing->shouldReceive('delete')->andReturn(false);
    Storage::set('public', $failing);

    expect(Artisan::call('app:secure-receipts', ['--boot' => true]))->toBe(1)
        ->and(Artisan::output())->toContain('exposed=1')->toContain('safe=no');

    Storage::set('public', $public);
    expect(Artisan::call('app:secure-receipts', ['--boot' => true]))->toBe(0)
        ->and(Artisan::output())->toContain('status=OK')->toContain('exposed=0')->toContain('safe=yes');
});
