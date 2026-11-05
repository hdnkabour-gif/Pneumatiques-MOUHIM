<?php

namespace App\Console\Commands;

use App\Services\Stock\StockVerifier;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;

#[Signature('stock:verify')]
#[Description('Check that cached stock quantities match the stock movement ledger')]
class VerifyStockCommand extends Command
{
    public function handle(StockVerifier $verifier): int
    {
        $differences = $verifier->differences();

        if ($differences === []) {
            $this->info(__('stock.verify.ok'));

            return self::SUCCESS;
        }

        $this->error(__('stock.verify.differences', ['count' => count($differences)]));
        $this->table(['kind', 'product_id', 'site_id', 'lot_id', 'cached', 'ledger'], $differences);

        return self::FAILURE;
    }
}
