<?php

use App\Enums\DocumentType;
use App\Filament\Pages\DocumentNumbering;
use App\Services\SequenceService;
use App\Services\SettingsService;
use Livewire\Livewire;

it('numbers invoices "219/26" without letters while other documents keep theirs', function () {
    app(SettingsService::class)->setMany([
        'documents.number_pattern' => '{PREFIX}{NUMBER}/{YY}',
        'documents.number_padding' => 1,
        'documents.prefix.invoice' => '',
    ]);
    $seq = app(SequenceService::class);
    $seq->setNextNumber(DocumentType::Invoice, (int) now()->year, 219);
    $yy = now()->format('y');

    expect($seq->nextDocumentNumber(DocumentType::Invoice))->toBe("219/{$yy}")
        ->and($seq->nextDocumentNumber(DocumentType::Invoice))->toBe("220/{$yy}")
        ->and($seq->nextDocumentNumber(DocumentType::Quote))->toBe("DV1/{$yy}");
});

it('applies the company format to the installation in use, once, and keeps an owner choice', function () {
    owner();
    $migration = require database_path('migrations/2026_11_11_090000_set_company_invoice_numbering.php');
    $migration->up();
    $settings = app(SettingsService::class);

    expect($settings->get('documents.number_pattern'))->toBe('{PREFIX}{NUMBER}/{YY}')
        ->and($settings->get('documents.prefix.invoice'))->toBe('');

    $settings->set('documents.number_pattern', '{PREFIX}-{NUMBER}-{YEAR}');
    $migration->up();
    expect(app(SettingsService::class)->get('documents.number_pattern'))->toBe('{PREFIX}-{NUMBER}-{YEAR}');
});

it('refuses two documents with the same prefix', function () {
    $this->actingAs(owner());
    $form = Livewire::test(DocumentNumbering::class);
    $form->set('data.invoice.prefix', '')->set('data.quote.prefix', '')->call('save');

    expect(app(SettingsService::class)->get('documents.prefix.quote'))->toBe('DV');
});
