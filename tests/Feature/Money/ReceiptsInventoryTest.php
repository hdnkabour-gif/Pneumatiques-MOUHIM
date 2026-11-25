<?php

use App\Enums\RoleName;
use App\Services\Money\CashRegister;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Storage;

/* #29 storage plan: the inventory is read-only and detects missing receipts and differing copies. */
function inventoryExpense(string $path): void
{
    app(CashRegister::class)->addExpense(site('M01'), ['category' => 'other', 'amount_cents' => 500,
        'description' => 'TEST', 'photo' => $path], userWithRole(RoleName::SiteManager, 'M01'));
}

it('lists each receipt with its location and SHA-256, changes nothing, and passes when all are present and identical', function () {
    Storage::fake('public');
    Storage::fake('documents');
    Storage::disk('documents')->put('expenses/a.jpg', 'AAA');
    Storage::disk('public')->put('expenses/b.jpg', 'BBB');
    Storage::disk('public')->put('expenses/c.jpg', 'CCC');
    Storage::disk('documents')->put('expenses/c.jpg', 'CCC');
    foreach (['a', 'b', 'c'] as $n) {
        inventoryExpense("expenses/{$n}.jpg");
    }

    expect(Artisan::call('app:receipts-inventory', ['--json' => true]))->toBe(0);
    $out = Artisan::output();
    expect($out)->toContain('RECEIPTS_INVENTORY total=3 private=1 public=1 both=1 missing=0 mismatch=0')
        ->toContain(hash('sha256', 'AAA'))->not->toContain('"AAA"');
    expect(Storage::disk('public')->exists('expenses/b.jpg'))->toBeTrue()                       // nothing moved
        ->and(Storage::disk('documents')->exists('expenses/b.jpg'))->toBeFalse();
});

it('fails when a receipt is missing or two copies differ', function () {
    Storage::fake('public');
    Storage::fake('documents');
    Storage::disk('public')->put('expenses/d.jpg', 'ONE');
    Storage::disk('documents')->put('expenses/d.jpg', 'TWO');
    inventoryExpense('expenses/d.jpg');
    inventoryExpense('expenses/gone.jpg');

    expect(Artisan::call('app:receipts-inventory'))->toBe(1)
        ->and(Artisan::output())->toContain('total=2')->toContain('missing=1')->toContain('mismatch=1');
});

it('also lists files in the receipts folder that have no expense row (same scope as app:secure-receipts)', function () {
    Storage::fake('public');
    Storage::fake('documents');
    Storage::disk('public')->put('expenses/orphan.jpg', 'ORPHAN');
    Storage::disk('public')->put('logo/brand.png', 'LOGO');

    expect(Artisan::call('app:receipts-inventory', ['--json' => true]))->toBe(0);
    $out = Artisan::output();
    expect($out)->toContain('"expense_id":null')->toContain('"path":"expenses/orphan.jpg"')->toContain(hash('sha256', 'ORPHAN'))
        ->toContain('total=1')->toContain('unreferenced=1')->not->toContain('brand.png');
});
