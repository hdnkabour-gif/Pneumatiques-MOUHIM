<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;

/**
 * Expense receipts were stored on the public disk (served as /storage/... without any check).
 * Move each one to the private "documents" disk under the SAME relative path, so the database
 * row does not change: copy, verify the SHA-256, then delete the public copy. Repeatable: a file
 * already moved is skipped; a missing file is reported (never invented). Product photos
 * (used tyres, equipment, logo) stay public.
 */
return new class extends Migration
{
    public function up(): void
    {
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
    }

    /** Not reversible on purpose: putting financial documents back into the public web tree is never wanted. */
    public function down(): void {}
};
