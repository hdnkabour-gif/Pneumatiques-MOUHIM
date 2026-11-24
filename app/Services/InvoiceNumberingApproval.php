<?php

namespace App\Services;

use App\Enums\DocumentType;
use App\Enums\SaleKind;
use App\Exceptions\BusinessRuleException;
use App\Models\Sale;
use App\Models\User;

/**
 * #20 (D-58): the owner approves the exact number the FIRST invoice will carry (format, prefix,
 * padding, year and next number in one string, e.g. "1251/26"). Until an invoice exists, an
 * invoice number is issued only if it equals that approved number; any later change of format
 * or next number makes the approval stale. Once the series is established the gate is off.
 */
class InvoiceNumberingApproval
{
    public const SETTING = 'documents.invoice_numbering_approved';

    public function __construct(private SettingsService $settings, private SequenceService $sequences) {}

    public function seriesEstablished(): bool
    {
        return Sale::query()->where('kind', SaleKind::Invoice->value)->whereNotNull('number')->exists();
    }

    /** The number the next invoice of the year would carry with the current settings. */
    public function nextInvoiceNumber(?int $year = null): string
    {
        $year ??= (int) now()->format('Y');

        return $this->sequences->format(DocumentType::Invoice, $year, $this->sequences->lastNumber(DocumentType::Invoice, $year) + 1);
    }

    public function problem(): ?string
    {
        if ($this->seriesEstablished()) {
            return null;
        }
        $next = $this->nextInvoiceNumber();

        return $this->settings->get(self::SETTING) === $next ? null
            : __('settings.numbering.errors.invoice_not_approved', ['number' => $next]);
    }

    /** Called by SequenceService with the number about to be issued (counter row already locked). */
    public function assertIssuable(string $number): void
    {
        if ($this->settings->get(self::SETTING) !== $number && ! $this->seriesEstablished()) {
            throw BusinessRuleException::make('settings.numbering.errors.invoice_not_approved', ['number' => $number]);
        }
    }

    public function approve(?User $by): string
    {
        $number = $this->nextInvoiceNumber();
        $this->settings->set(self::SETTING, $number, $by);
        activity('settings')->causedBy($by)->event('invoice_numbering_approved')
            ->withProperties(['first_invoice_number' => $number])
            ->log('invoice_numbering_approved');

        return $number;
    }
}
