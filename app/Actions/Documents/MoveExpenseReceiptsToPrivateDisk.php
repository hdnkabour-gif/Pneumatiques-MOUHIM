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
 * A receipt counts as moved only once its public copy is really gone: the public disk does not
 * throw on a failed delete, so it is checked; a failure is reported (delete_failed), the verified
 * private copy is kept, and the next run retries.
 *
 * Files in the public receipts folder WITHOUT an expense row (the old cash desk stored the upload
 * before the expense could be refused) are receipts too: they are preserved the same way (verified
 * private copy, then public copy removed) and counted as "unreferenced", never deleted unverified.
 *
 * @return array{moved: int, missing: int, delete_failed: int, unreferenced: int, skipped: ?string}
 */
class MoveExpenseReceiptsToPrivateDisk
{
    /** Folder the cash desk has always used for receipts (CashDesk FileUpload ->directory()). */
    public const RECEIPTS_FOLDER = 'expenses';

    /** Every receipt that can be on the public disk: referenced paths plus any file in the receipts folder. */
    public static function candidates(): array
    {
        $referenced = DB::table('expenses')->whereNotNull('photo')->orderBy('id')->pluck('photo')->all();
        $inFolder = Storage::disk('public')->allFiles(self::RECEIPTS_FOLDER);

        return ['referenced' => $referenced, 'unreferenced' => array_values(array_diff($inFolder, $referenced))];
    }

    public function __invoke(): array
    {
        if ($problem = app(DocumentStorageGuard::class)->problem()) {
            Log::warning('Expense receipts NOT moved to the private disk: '.$problem);

            return ['moved' => 0, 'missing' => 0, 'delete_failed' => 0, 'unreferenced' => count(self::candidates()['unreferenced']), 'skipped' => $problem];
        }
        $public = Storage::disk('public');
        $documents = Storage::disk('documents');
        $moved = 0;
        $missing = 0;
        $deleteFailed = 0;

        $candidates = self::candidates();
        $unreferenced = array_flip($candidates['unreferenced']);
        foreach ([...$candidates['referenced'], ...$candidates['unreferenced']] as $path) {
            if (! $public->exists($path)) {
                isset($unreferenced[$path]) || $documents->exists($path) || $missing++;

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
            if ($public->exists($path)) {
                $deleteFailed++;
                Log::error("Receipt [{$path}] copied to the private disk but its PUBLIC copy could not be deleted: it is still reachable.");

                continue;
            }
            $moved++;
        }
        if ($moved || $missing || $deleteFailed) {
            Log::info('Expense receipts secured.', ['moved' => $moved, 'missing' => $missing, 'delete_failed' => $deleteFailed, 'unreferenced' => count($unreferenced)]);
        }

        return ['moved' => $moved, 'missing' => $missing, 'delete_failed' => $deleteFailed, 'unreferenced' => count($unreferenced), 'skipped' => null];
    }
}
