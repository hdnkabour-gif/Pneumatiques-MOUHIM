<?php

// Child process for the cash-day race tests (#17): runs ONE cash operation on its own
// PostgreSQL connection and prints JSON. The parent coordinates with row-lock barriers.
//   cash-op.php close       <siteId> <userId> <counted>
//   cash-op.php receipt     <customerId> <siteId> <userId> <amount>
//   cash-op.php sale        <saleId> <userId> <amount>
//   cash-op.php credit_note <invoiceId> <lineId> <ownerId>
//   cash-op.php expense     <siteId> <userId> <amount>
//   cash-op.php float       <siteId> <userId> <amount>

use App\Actions\Money\CustomerAccountActions;
use App\Actions\Sales\ConfirmSaleAction;
use App\Actions\Sales\CreateCreditNoteAction;
use App\Enums\RefundMethod;
use App\Models\Customer;
use App\Models\Sale;
use App\Models\Site;
use App\Models\User;
use App\Services\Money\CashRegister;
use Illuminate\Contracts\Console\Kernel;

$op = $argv[1];
$args = array_slice($argv, 2);

require __DIR__.'/../../../vendor/autoload.php';
$app = require __DIR__.'/../../../bootstrap/app.php';
$app->make(Kernel::class)->bootstrap();

try {
    $cash = app(CashRegister::class);
    $out = match ($op) {
        'close' => ['day' => $cash->close(Site::query()->findOrFail((int) $args[0]), (int) $args[2], 'TEST', User::query()->findOrFail((int) $args[1]))->id],
        'receipt' => ['payment' => app(CustomerAccountActions::class)->receive(Customer::query()->findOrFail((int) $args[0]), Site::query()->findOrFail((int) $args[1]),
            ['method' => 'cash', 'amount_cents' => (int) $args[3]], User::query()->findOrFail((int) $args[2]))->id],
        'sale' => ['sale' => app(ConfirmSaleAction::class)->execute(Sale::query()->findOrFail((int) $args[0]),
            [['method' => 'cash', 'amount_cents' => (int) $args[2]]], User::query()->findOrFail((int) $args[1]))->id],
        'credit_note' => ['note' => app(CreateCreditNoteAction::class)->request(Sale::query()->findOrFail((int) $args[0]),
            [(int) $args[1] => ['quantity' => 1, 'restock' => true]], RefundMethod::Cash, 'TEST retour', User::query()->findOrFail((int) $args[2]))->id],
        'expense' => ['expense' => $cash->addExpense(Site::query()->findOrFail((int) $args[0]),
            ['category' => 'other', 'amount_cents' => (int) $args[2], 'description' => 'TEST dépense'], User::query()->findOrFail((int) $args[1]))->id],
        'float' => ['float' => $cash->setFloat(Site::query()->findOrFail((int) $args[0]), (int) $args[2], User::query()->findOrFail((int) $args[1])) ?? true],
    };
    echo json_encode(['result' => 'POSTED'] + $out);
} catch (Throwable $e) {
    echo json_encode(['result' => 'REFUSED', 'error' => class_basename($e), 'message' => $e->getMessage()]);
}
