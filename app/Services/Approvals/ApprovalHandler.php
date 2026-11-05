<?php

namespace App\Services\Approvals;

use App\Models\ApprovalRequest;
use App\Models\User;

/** Executes the operation once an approval request is granted. */
interface ApprovalHandler
{
    public function execute(ApprovalRequest $request, User $approver): void;

    /** One line describing the request for the approver (translated). */
    public function summary(ApprovalRequest $request): string;
}
