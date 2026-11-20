<?php

// Child process for the concurrency tests (#18). Waits for a shared start time, then
// confirms one draft sale with no payment (everything on credit) and prints JSON.
// Uses the same environment (DB_*) as the parent test process. Synthetic data only.

use App\Actions\Sales\ConfirmSaleAction;
use App\Models\Sale;
use App\Models\User;
use Illuminate\Contracts\Console\Kernel;

[$_, $saleId, $sellerId, $startAt] = $argv;

require __DIR__.'/../../../vendor/autoload.php';
$app = require __DIR__.'/../../../bootstrap/app.php';
$app->make(Kernel::class)->bootstrap();

$sale = Sale::query()->findOrFail((int) $saleId);
$seller = User::query()->findOrFail((int) $sellerId);
while (microtime(true) < (float) $startAt) {
    usleep(200);
}
try {
    $confirmed = app(ConfirmSaleAction::class)->execute($sale, [], $seller);
    echo json_encode(['sale' => (int) $saleId, 'result' => 'CONFIRMED', 'credit_cents' => $confirmed->credit_cents]);
} catch (Throwable $e) {
    echo json_encode(['sale' => (int) $saleId, 'result' => 'REFUSED', 'error' => class_basename($e)]);
}
