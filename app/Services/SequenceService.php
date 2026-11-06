<?php

namespace App\Services;

use App\Enums\DocumentType;
use App\Exceptions\BusinessRuleException;
use Illuminate\Support\Facades\DB;

/**
 * Atomic, gap-free counters. The increment is a single UPSERT holding a row
 * lock until the surrounding transaction ends, so two sites creating a
 * document at the same moment never get the same number (D-14, §21).
 */
class SequenceService
{
    public function next(string $key): int
    {
        $row = DB::selectOne(
            'INSERT INTO sequences (key, last_value, created_at, updated_at) VALUES (?, 1, now(), now())
             ON CONFLICT (key) DO UPDATE SET last_value = sequences.last_value + 1, updated_at = now()
             RETURNING last_value',
            [$key],
        );

        return (int) $row->last_value;
    }

    /** SKU with the category prefix (D-41): "PN-000123". */
    public function nextSku(string $prefix): string
    {
        $prefix = strtoupper($prefix);

        return $prefix.'-'.str_pad((string) $this->next("sku:{$prefix}"), 6, '0', STR_PAD_LEFT);
    }

    /**
     * Document number: one series per document type and year for the whole
     * company (D-14), with the owner's prefix and pattern (D-30, D-49).
     */
    public function nextDocumentNumber(DocumentType $type, ?int $year = null): string
    {
        $year ??= (int) now()->format('Y');

        return $this->format($type, $year, $this->next($type->sequenceKey($year)));
    }

    public function format(DocumentType $type, int $year, int $number): string
    {
        $settings = app(SettingsService::class);

        return strtr((string) $settings->get('documents.number_pattern'), [
            '{PREFIX}' => (string) ($settings->get($type->prefixSetting()) ?: $type->defaultPrefix()),
            '{YEAR}' => (string) $year,
            '{NUMBER}' => str_pad((string) $number, (int) $settings->get('documents.number_padding'), '0', STR_PAD_LEFT),
        ]);
    }

    public function lastNumber(DocumentType $type, int $year): int
    {
        return (int) DB::table('sequences')->where('key', $type->sequenceKey($year))->value('last_value');
    }

    /**
     * Continue the numbering of the company's previous system (D-49): the next
     * document of the year will carry $nextNumber. Going back is refused, so a
     * number can never be issued twice.
     */
    public function setNextNumber(DocumentType $type, int $year, int $nextNumber): void
    {
        DB::transaction(function () use ($type, $year, $nextNumber) {
            DB::statement(
                'INSERT INTO sequences (key, last_value, created_at, updated_at) VALUES (?, 0, now(), now()) ON CONFLICT (key) DO NOTHING',
                [$type->sequenceKey($year)],
            );
            $current = (int) DB::table('sequences')->where('key', $type->sequenceKey($year))->lockForUpdate()->value('last_value');

            if ($nextNumber <= $current) {
                throw BusinessRuleException::make('settings.errors.number_backwards', [
                    'type' => $type->label(), 'last' => $current,
                ]);
            }

            DB::table('sequences')->where('key', $type->sequenceKey($year))->update(['last_value' => $nextNumber - 1, 'updated_at' => now()]);
        });
    }
}
