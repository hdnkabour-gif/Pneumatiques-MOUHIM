<?php

use App\Enums\RoleName;
use App\Exceptions\BusinessRuleException;
use App\Services\Money\CashRegister;
use App\Support\DocumentStorageGuard;
use Illuminate\Support\Facades\Artisan;
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
    onRailway('/nonexistent-volume');
    Artisan::call('app:go-live-audit');
    expect(Artisan::output())->toContain('Private documents storage: FAIL');
    putenv('RAILWAY_VOLUME_MOUNT_PATH='.storage_path('app'));
    Artisan::call('app:go-live-audit');
    expect(Artisan::output())->toContain('Private documents storage: PASS');
});
