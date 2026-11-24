<?php

namespace App\Console\Commands;

use App\Actions\Documents\MoveExpenseReceiptsToPrivateDisk;
use App\Services\Backups\BackupAlerts;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;

#[Signature('app:secure-receipts')]
#[Description('Move expense receipts off the public disk once private storage is durable (idempotent, run at every boot)')]
class SecureReceiptsCommand extends Command
{
    /**
     * Machine-readable last line: SECURE_RECEIPTS status=OK|FAIL|SKIPPED moved= missing= delete_failed=.
     * Non-zero exit for FAIL and SKIPPED (a release gate); FAIL also alerts the owners.
     */
    public function handle(MoveExpenseReceiptsToPrivateDisk $move, BackupAlerts $alerts): int
    {
        $r = $move();
        $status = $r['skipped'] !== null ? 'SKIPPED' : ($r['missing'] > 0 || $r['delete_failed'] > 0 ? 'FAIL' : 'OK');
        if ($r['skipped'] !== null) {
            $this->warn('Receipts not moved (they stay on the public volume): '.$r['skipped']);
        }
        if ($status === 'FAIL') {
            $this->error("Receipts need attention: {$r['missing']} missing, {$r['delete_failed']} public copies not deleted.");
            $alerts->taskFailed('app:secure-receipts');
        }
        $this->line("SECURE_RECEIPTS status={$status} moved={$r['moved']} missing={$r['missing']} delete_failed={$r['delete_failed']}");

        return $status === 'OK' ? self::SUCCESS : self::FAILURE;
    }
}
