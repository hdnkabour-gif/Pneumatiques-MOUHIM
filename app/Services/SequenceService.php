<?php

namespace App\Services;

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
     * company (D-14), formatted with the configurable pattern (D-30).
     */
    public function nextDocumentNumber(string $prefix, ?int $year = null): string
    {
        $year ??= (int) now()->format('Y');
        $settings = app(SettingsService::class);
        $number = $this->next("doc:{$prefix}:{$year}");

        return strtr((string) $settings->get('documents.number_pattern'), [
            '{PREFIX}' => $prefix,
            '{YEAR}' => (string) $year,
            '{NUMBER}' => str_pad((string) $number, (int) $settings->get('documents.number_padding'), '0', STR_PAD_LEFT),
        ]);
    }
}
