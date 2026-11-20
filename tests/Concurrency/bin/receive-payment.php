<?php

// Child process for the concurrency tests (#21): waits for a shared start time, then
// records one customer receipt with the given operation key and prints JSON.

use App\Actions\Money\CustomerAccountActions;
use App\Models\Customer;
use App\Models\Site;
use App\Models\User;
use Illuminate\Contracts\Console\Kernel;

[$_, $customerId, $siteId, $userId, $key, $amount, $startAt] = $argv;

require __DIR__.'/../../../vendor/autoload.php';
$app = require __DIR__.'/../../../bootstrap/app.php';
$app->make(Kernel::class)->bootstrap();

$customer = Customer::query()->findOrFail((int) $customerId);
$site = Site::query()->findOrFail((int) $siteId);
$user = User::query()->findOrFail((int) $userId);
while (microtime(true) < (float) $startAt) {
    usleep(200);
}
try {
    $p = app(CustomerAccountActions::class)->receive($customer, $site, ['method' => 'card', 'amount_cents' => (int) $amount, 'idempotency_key' => $key], $user);
    echo json_encode(['result' => 'POSTED', 'payment' => $p->id]);
} catch (Throwable $e) {
    echo json_encode(['result' => 'REFUSED', 'error' => class_basename($e), 'message' => $e->getMessage()]);
}
