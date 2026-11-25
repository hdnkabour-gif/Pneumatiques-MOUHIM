<?php

namespace App\Console\Commands;

use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;

/**
 * #29 storage plan, step "inventory": READ-ONLY. For every expense receipt it reports where the file
 * is (public disk, private documents disk, both, or missing), its size and SHA-256, and whether two
 * copies are identical. It never prints file contents and changes nothing, so it can be run before
 * and after a volume change or a restore and the two outputs compared line by line.
 */
#[Signature('app:receipts-inventory {--json : One JSON object per receipt, then a summary line}')]
#[Description('Read-only inventory of expense receipts with SHA-256 checksums (before/after a storage move or restore)')]
class ReceiptsInventoryCommand extends Command
{
    public function handle(): int
    {
        $public = Storage::disk('public');
        $documents = Storage::disk('documents');
        $sum = fn ($disk, $path) => $disk->exists($path) ? ['size' => $disk->size($path), 'sha256' => hash('sha256', $disk->get($path))] : null;
        $counts = ['total' => 0, 'private' => 0, 'public' => 0, 'both' => 0, 'missing' => 0, 'mismatch' => 0];
        $rows = [];

        foreach (DB::table('expenses')->whereNotNull('photo')->orderBy('id')->get(['id', 'site_id', 'photo']) as $e) {
            $p = $sum($public, $e->photo);
            $d = $sum($documents, $e->photo);
            $location = $p && $d ? 'both' : ($d ? 'private' : ($p ? 'public' : 'missing'));
            $mismatch = $p && $d && $p['sha256'] !== $d['sha256'];
            $counts['total']++;
            $counts[$location]++;
            $mismatch && $counts['mismatch']++;
            $rows[] = ['expense_id' => $e->id, 'site_id' => $e->site_id, 'path' => $e->photo, 'location' => $location,
                'size' => ($d ?? $p)['size'] ?? null, 'sha256' => ($d ?? $p)['sha256'] ?? null, 'copies_identical' => $p && $d ? ! $mismatch : null];
        }

        if ($this->option('json')) {
            foreach ($rows as $row) {
                $this->line(json_encode($row, JSON_UNESCAPED_SLASHES));
            }
        } else {
            $this->table(['Expense', 'Site', 'Location', 'Size', 'SHA-256'], array_map(fn ($r) => [$r['expense_id'], $r['site_id'], $r['location'], $r['size'] ?? '—', $r['sha256'] ? substr($r['sha256'], 0, 16).'…' : '—'], $rows));
        }
        $this->line('RECEIPTS_INVENTORY '.implode(' ', array_map(fn ($k, $v) => "{$k}={$v}", array_keys($counts), $counts)));

        return $counts['missing'] === 0 && $counts['mismatch'] === 0 ? self::SUCCESS : self::FAILURE;
    }
}
