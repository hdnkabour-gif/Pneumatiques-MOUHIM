<?php

namespace App\Console\Commands;

use App\Actions\Documents\MoveExpenseReceiptsToPrivateDisk;
use App\Services\Backups\BackupAlerts;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;

#[Signature('app:secure-receipts {--boot : Exit non-zero only when a receipt is still reachable on the public disk (the entrypoint then refuses to open the server)}')]
#[Description('Move expense receipts off the public disk once private storage is durable (idempotent, run at every boot)')]
class SecureReceiptsCommand extends Command
{
    /**
     * Machine-readable last line:
     * SECURE_RECEIPTS status=OK|FAIL|SKIPPED moved= missing= delete_failed= exposed= safe=yes|no
     * exposed = receipts still on the public (served) disk after this run; safe = exposed is 0.
     * Default: non-zero exit for FAIL and SKIPPED (a release gate); FAIL also alerts the owners.
     * --boot (#31 review): non-zero exit only when not safe, so the server never opens while an
     * old receipt is reachable; originals are never deleted before a verified private copy.
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
        $public = Storage::disk('public');
        $exposed = DB::table('expenses')->whereNotNull('photo')->pluck('photo')->filter(fn ($path) => $public->exists($path))->count();
        if ($exposed > 0) {
            $this->error("{$exposed} receipt(s) still on the public disk: do not open the server until they are secured.");
        }
        $this->line("SECURE_RECEIPTS status={$status} moved={$r['moved']} missing={$r['missing']} delete_failed={$r['delete_failed']} exposed={$exposed} safe=".($exposed === 0 ? 'yes' : 'no'));

        if ($this->option('boot')) {
            return $exposed === 0 ? self::SUCCESS : self::FAILURE;
        }

        return $status === 'OK' ? self::SUCCESS : self::FAILURE;
    }
}
