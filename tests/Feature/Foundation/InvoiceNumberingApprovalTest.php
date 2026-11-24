<?php

use App\Enums\DocumentType;
use App\Enums\RoleName;
use App\Exceptions\BusinessRuleException;
use App\Filament\Pages\DocumentNumbering;
use App\Models\Activity;
use App\Services\InvoiceNumberingApproval;
use App\Services\SequenceService;
use App\Services\SettingsService;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Livewire\Livewire;

/*
 * #20 (D-58): the FIRST invoice of an installation is issued only in a numbering format and
 * starting number the owner explicitly approved. A fresh install keeps the generic defaults
 * (FA-2026-000001), so without this gate the first real invoice could carry a number that does
 * not continue the paper books. Once one invoice exists, the series is established and the
 * gate no longer applies (numbers are never rewritten).
 */
beforeEach(function () {
    app(SettingsService::class)->set('documents.invoice_numbering_approved', null);   // a fresh install
});

function d58(int $next = 220): void
{
    app(SettingsService::class)->setMany([
        'documents.number_pattern' => '{PREFIX}{NUMBER}/{YY}',
        'documents.number_padding' => 1,
        'documents.prefix.invoice' => '',
    ]);
    app(SequenceService::class)->setNextNumber(DocumentType::Invoice, (int) now()->year, $next);
}

it('refuses the first invoice while the numbering is not approved, consumes no number, and leaves other documents alone', function () {
    $seq = app(SequenceService::class);

    expect(fn () => DB::transaction(fn () => $seq->nextDocumentNumber(DocumentType::Invoice)))
        ->toThrow(BusinessRuleException::class, __('settings.numbering.errors.invoice_not_approved', ['number' => 'FA-'.now()->year.'-000001']));
    expect($seq->lastNumber(DocumentType::Invoice, (int) now()->year))->toBe(0)
        ->and($seq->nextDocumentNumber(DocumentType::Quote))->toBe('DV-'.now()->year.'-000001');
});

it('issues the first invoice with exactly the approved number, then continues normally', function () {
    d58();
    app(InvoiceNumberingApproval::class)->approve(owner());
    $yy = now()->format('y');

    expect(issueInvoice())->toBe("220/{$yy}")
        ->and(issueInvoice())->toBe("221/{$yy}");
});

it('invalidates the approval when the format or the next number changes before the first invoice', function () {
    d58();
    $approval = app(InvoiceNumberingApproval::class);
    $approval->approve(owner());
    app(SettingsService::class)->set('documents.number_padding', 4);            // 0220/26: not what was approved

    expect($approval->problem())->not->toBeNull()
        ->and(fn () => app(SequenceService::class)->nextDocumentNumber(DocumentType::Invoice))->toThrow(BusinessRuleException::class);

    app(SettingsService::class)->set('documents.number_padding', 1);
    app(SequenceService::class)->setNextNumber(DocumentType::Invoice, (int) now()->year, 300);  // 300/26: not approved either
    expect(fn () => app(SequenceService::class)->nextDocumentNumber(DocumentType::Invoice))->toThrow(BusinessRuleException::class);
});

it('does not block an installation whose invoice series is already established', function () {
    d58();
    app(InvoiceNumberingApproval::class)->approve(owner());
    issueInvoice();
    app(SettingsService::class)->setMany(['documents.invoice_numbering_approved' => null, 'documents.number_padding' => 3]);

    expect(app(InvoiceNumberingApproval::class)->problem())->toBeNull()
        ->and(app(SequenceService::class)->nextDocumentNumber(DocumentType::Invoice))->toBe('221/'.now()->format('y'));
});

it('lets only a settings manager approve on the numbering page, and audits who approved which number', function () {
    d58(1251);
    Livewire::actingAs(userWithRole(RoleName::Seller, 'M01'))->test(DocumentNumbering::class)->assertForbidden();

    $owner = owner();
    Livewire::actingAs($owner)->test(DocumentNumbering::class)->callAction('approveInvoiceNumbering');

    $yy = now()->format('y');
    expect(app(SettingsService::class)->get('documents.invoice_numbering_approved'))->toBe("1251/{$yy}")
        ->and(Activity::query()->where('event', 'invoice_numbering_approved')->latest('id')->first())
        ->causer_id->toBe($owner->id)
        ->properties->toArray()->toMatchArray(['first_invoice_number' => "1251/{$yy}"]);
});

it('reports the approval in the go-live audit and fails the strict audit without it', function () {
    Artisan::call('app:go-live-audit');
    expect(Artisan::output())->toContain('Invoice numbering approval: FAIL');
    expect(Artisan::call('app:go-live-audit', ['--strict' => true]))->toBe(1);

    app(InvoiceNumberingApproval::class)->approve(owner());
    Artisan::call('app:go-live-audit');
    expect(Artisan::output())->toContain('Invoice numbering approval: PASS');
});

/*
 * #32 review (P1): the approval is bound to the number the owner SAW. If another manager or tab
 * changes the next number between the confirmation window and the click, the old confirmation is
 * refused and nothing is approved; a fresh window shows (and approves) the new number.
 */
it('refuses a confirmation made on a number that changed meanwhile, then approves the re-read number', function () {
    d58(1251);
    $yy = now()->format('y');
    app(SequenceService::class)->setNextNumber(DocumentType::Invoice, (int) now()->year, 1300);   // the other tab

    expect(fn () => app(InvoiceNumberingApproval::class)->approve(owner(), "1251/{$yy}"))
        ->toThrow(BusinessRuleException::class, __('settings.numbering.errors.approval_stale', ['shown' => "1251/{$yy}", 'current' => "1300/{$yy}"]));
    expect(app(SettingsService::class)->get('documents.invoice_numbering_approved'))->toBeNull();

    $owner = owner();
    Livewire::actingAs($owner)->test(DocumentNumbering::class)
        ->callAction('approveInvoiceNumbering', data: ['number' => "1251/{$yy}"])   // the stale window
        ->assertNotified(__('settings.numbering.errors.approval_stale', ['shown' => "1251/{$yy}", 'current' => "1300/{$yy}"]));
    expect(app(SettingsService::class)->get('documents.invoice_numbering_approved'))->toBeNull();

    Livewire::actingAs($owner)->test(DocumentNumbering::class)->callAction('approveInvoiceNumbering');   // re-read
    expect(app(SettingsService::class)->get('documents.invoice_numbering_approved'))->toBe("1300/{$yy}");
});
