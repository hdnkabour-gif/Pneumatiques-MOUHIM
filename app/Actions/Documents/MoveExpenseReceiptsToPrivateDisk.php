<?php

namespace App\Actions\Documents;

use App\Support\DocumentStorageGuard;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use RuntimeException;

/**
 * Moves expense receipts from the public disk to the private "documents" disk under the SAME
 * relative path (the database row does not change): copy, verify SHA-256, then delete the
 * public copy. Idempotent and run at every boot (app:secure-receipts), so it heals itself:
 *  - while the documents storage is not durable (#29), NOTHING is moved and the public copies
 *    stay on the persistent volume;
 *  - once it is durable, the next boot moves what is left.
 * A missing file is reported, never invented. Product photos stay public.
 *
 * @return array{moved: int, missing: int, skipped: ?string}
 */
class MoveExpenseReceiptsToPrivateDisk
{
    public function __invoke(): array
    {
        if ($problem = app(DocumentStorageGuard::class)->problem()) {
            Log::warning('Expense receipts NOT moved to the private disk: '.$problem);

            return ['moved' => 0, 'missing' => 0, 'skipped' => $problem];
        }
        $public = Storage::disk('public');
        $documents = Storage::disk('documents');
        $moved = 0;
        $missing = 0;

        foreach (DB::table('expenses')->whereNotNull('photo')->orderBy('id')->pluck('photo') as $path) {
            if (! $public->exists($path)) {
                $documents->exists($path) || $missing++;

                continue;
            }
            $bytes = $public->get($path);
            if (! $documents->exists($path) || hash('sha256', $documents->get($path)) !== hash('sha256', $bytes)) {
                $documents->put($path, $bytes);
            }
            if (hash('sha256', $documents->get($path)) !== hash('sha256', $bytes)) {
                throw new RuntimeException("Receipt [{$path}] could not be copied to the private disk intact; nothing was deleted.");
            }
            $public->delete($path);
            $moved++;
        }
        if ($moved || $missing) {
            Log::info('Expense receipts moved to the private documents disk.', ['moved' => $moved, 'missing' => $missing]);
        }

        return ['moved' => $moved, 'missing' => $missing, 'skipped' => null];
    }
}
