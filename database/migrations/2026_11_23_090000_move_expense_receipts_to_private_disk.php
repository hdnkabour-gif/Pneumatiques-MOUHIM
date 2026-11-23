<?php

use App\Actions\Documents\MoveExpenseReceiptsToPrivateDisk;
use Illuminate\Database\Migrations\Migration;

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
        // Same idempotent action as `app:secure-receipts`, which the entrypoint also runs at
        // every boot: if storage is not durable yet (#29) nothing moves now, and a later boot
        // moves the receipts once it is.
        app(MoveExpenseReceiptsToPrivateDisk::class)();
    }

    /** Not reversible on purpose: putting financial documents back into the public web tree is never wanted. */
    public function down(): void {}
};
