<?php

namespace App\Console\Commands;

use App\Actions\Documents\MoveExpenseReceiptsToPrivateDisk;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;

#[Signature('app:secure-receipts')]
#[Description('Move expense receipts off the public disk once private storage is durable (idempotent, run at every boot)')]
class SecureReceiptsCommand extends Command
{
    public function handle(MoveExpenseReceiptsToPrivateDisk $move): int
    {
        $result = $move();
        if ($result['skipped'] !== null) {
            $this->warn('Receipts not moved (they stay on the public volume): '.$result['skipped']);

            return self::FAILURE;
        }
        $this->info("Receipts moved: {$result['moved']}; missing files: {$result['missing']}.");

        return self::SUCCESS;
    }
}
