<?php

namespace App\Http\Controllers;

use App\Enums\Permission;
use App\Models\Expense;
use Illuminate\Support\Facades\Storage;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * An expense receipt is a financial document: it is never a public file. It is read from the
 * private "documents" disk only by a signed-in user who has access to the cash page (same rule
 * as CashDesk::canAccess) AND to the expense's site. Responses are never cached.
 */
class ExpensePhotoController
{
    public function __invoke(Expense $expense): StreamedResponse
    {
        $user = auth()->user();
        abort_unless($user && $user->canAny([Permission::ExpensesCreate->value, Permission::CashClose->value]), 403);
        abort_unless($user->canAccessSite($expense->site_id), 403);

        $disk = Storage::disk('documents');
        abort_unless($expense->photo && $disk->exists($expense->photo), 404);

        return $disk->response($expense->photo, basename($expense->photo), [
            'Cache-Control' => 'private, no-store, max-age=0',
            'X-Content-Type-Options' => 'nosniff',
        ], 'inline');
    }
}
