<?php

use App\Enums\RoleName;
use App\Filament\Pages\CashDesk;
use App\Models\Expense;
use App\Services\Money\CashRegister;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Livewire\Livewire;

/*
 * Expense receipts are financial documents: they must never be reachable as a public file
 * (/storage/...). They live on the private "documents" disk and are served only through an
 * authenticated route that checks the cash permission AND the expense's site.
 */
beforeEach(function () {
    Storage::fake('public');
    Storage::fake('documents');
    $this->seller = userWithRole(RoleName::Seller, 'M01');
    $this->expenseWithPhoto = function (string $site = 'M01', string $bytes = 'RECEIPT-BYTES') {
        Storage::disk('documents')->put('expenses/receipt-test.jpg', $bytes);

        return app(CashRegister::class)->addExpense(site($site), ['category' => 'transport', 'amount_cents' => 1200,
            'description' => 'TEST taxi', 'photo' => 'expenses/receipt-test.jpg'], userWithRole(RoleName::SiteManager, $site));
    };
});

it('stores an uploaded receipt on the private documents disk, never on the public disk', function () {
    $this->seller->update(['default_site_id' => site('M01')->id]);
    $this->actingAs($this->seller);

    Livewire::test(CashDesk::class)
        ->callAction('expense', ['amount_cents' => '35,50', 'category' => 'transport', 'description' => 'TEST taxi',
            'photo' => [UploadedFile::fake()->image('ticket.jpg')], 'paid_in_cash' => true])
        ->assertHasNoActionErrors();

    $photo = Expense::query()->firstOrFail()->photo;
    expect($photo)->toStartWith('expenses/')
        ->and(Storage::disk('documents')->exists($photo))->toBeTrue()
        ->and(Storage::disk('public')->exists($photo))->toBeFalse()
        ->and(config('filesystems.disks.documents.root'))->not->toContain('app/public');
});

it('serves a receipt only to a signed-in user with cash access on the expense site', function () {
    $expense = ($this->expenseWithPhoto)();
    $url = route('expenses.photo', $expense);

    $this->get($url)->assertRedirect();                                                          // guest
    $this->actingAs(userWithRole(RoleName::Storekeeper, 'M01'))->get($url)->assertForbidden();    // no cash permission
    $this->actingAs(userWithRole(RoleName::Seller, 'M02'))->get($url)->assertForbidden();         // another site

    $ok = $this->actingAs($this->seller)->get($url);
    $ok->assertOk();
    expect($ok->streamedContent())->toBe('RECEIPT-BYTES')
        ->and($ok->headers->get('Cache-Control'))->toContain('no-store')
        ->and($ok->headers->get('X-Content-Type-Options'))->toBe('nosniff');

    Storage::disk('documents')->delete('expenses/receipt-test.jpg');
    $this->actingAs($this->seller)->get($url)->assertNotFound();                                  // file gone: 404, no error page leak
});

it('links the receipt through the protected route on the cash page, not through /storage', function () {
    ($this->expenseWithPhoto)();
    $this->seller->update(['default_site_id' => site('M01')->id]);

    $html = $this->actingAs($this->seller)->get(CashDesk::getUrl())->assertOk()->getContent();
    expect($html)->toContain(route('expenses.photo', Expense::query()->firstOrFail()))
        ->and($html)->not->toContain('/storage/expenses/');
});

it('moves receipts already on the public disk to the private disk once, verified, and is repeatable', function () {
    Storage::disk('public')->put('expenses/old-receipt.jpg', 'OLD-RECEIPT');
    $expense = app(CashRegister::class)->addExpense(site('M01'), ['category' => 'other', 'amount_cents' => 500,
        'description' => 'TEST ancien', 'photo' => 'expenses/old-receipt.jpg'], userWithRole(RoleName::SiteManager, 'M01'));
    Storage::disk('public')->put('used-tyres/not-a-receipt.jpg', 'PRODUCT-PHOTO');   // product photos stay public

    $migration = require database_path('migrations/2026_11_23_090000_move_expense_receipts_to_private_disk.php');
    $migration->up();
    $migration->up();   // repeatable: nothing left to move, nothing broken

    expect(Storage::disk('documents')->get('expenses/old-receipt.jpg'))->toBe('OLD-RECEIPT')
        ->and(Storage::disk('public')->exists('expenses/old-receipt.jpg'))->toBeFalse()
        ->and(Storage::disk('public')->get('used-tyres/not-a-receipt.jpg'))->toBe('PRODUCT-PHOTO')
        ->and($expense->fresh()->photo)->toBe('expenses/old-receipt.jpg');
});
