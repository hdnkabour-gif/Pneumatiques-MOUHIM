<?php

namespace App\Actions\Staging;

use App\Actions\Customers\CreateCustomerAction;
use App\Actions\Money\CustomerAccountActions;
use App\Actions\Purchases\PurchaseActions;
use App\Actions\Sales\ConfirmSaleAction;
use App\Actions\Sales\SaveSaleDraftAction;
use App\Actions\Transfers\ReceiveTransferAction;
use App\Actions\Transfers\SendTransferAction;
use App\Actions\UsedTyres\UsedTyreActions;
use App\Actions\Users\SaveUserAction;
use App\Enums\CustomerType;
use App\Enums\PriceLevel;
use App\Enums\ProductKind;
use App\Enums\RoleName;
use App\Enums\Unit;
use App\Enums\UsedTyreCheck;
use App\Enums\UsedTyreOrigin;
use App\Models\Category;
use App\Models\Customer;
use App\Models\Product;
use App\Models\Site;
use App\Models\Supplier;
use App\Models\User;
use App\Services\Money\CashRegister;
use App\Support\StagingGuard;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use RuntimeException;
use Throwable;

/**
 * A complete, clearly fictitious working day on the three sites, for Staging only:
 * purchase order and two receptions at the depot, transfers to both shops (one still in
 * transit), cash, card and credit sales with their invoices, an opening balance, a cheque,
 * a supplier payment, a used tyre and an expense with photos, and the closing of the M01 till.
 *
 * Everything goes through the application's own actions (stock never written directly) and
 * runs in one transaction: if a step fails, nothing is kept. Every name says "(démo)"; the
 * demo staff accounts get random passwords that are never shown, so nobody can log in with them.
 */
class CreateStagingDemoDataAction
{
    /** @var list<string> */
    private array $photos = [];

    /** @return array<string, mixed> what was created, for the report */
    public function execute(User $owner): array
    {
        if ($refusal = StagingGuard::refusal()) {
            throw new RuntimeException("Demo data refused: {$refusal}");
        }
        if (! $owner->isOwner() || ! $owner->is_active) {
            throw new RuntimeException('Demo data needs an active owner account.');
        }

        $dep = $this->site('DEP');
        $m01 = $this->site('M01');
        $m02 = $this->site('M02');
        if (app(CashRegister::class)->day($m01)->isClosed()) {
            throw new RuntimeException('The M01 till is already closed today (demo data already created today?): nothing was changed.');
        }

        try {
            return DB::transaction(fn () => $this->createDay($owner, $dep, $m01, $m02));
        } catch (Throwable $e) {
            Storage::disk('public')->delete($this->photos);
            Storage::disk('documents')->delete($this->photos);

            throw $e;
        }
    }

    private function createDay(User $owner, Site $dep, Site $m01, Site $m02): array
    {
        $batch = now()->format('YmdHis');

        // Staff (no usable password).
        $staff = fn (RoleName $role, Site $site, string $label) => app(SaveUserAction::class)->execute(null, [
            'name' => "{$label} (démo)", 'email' => "demo-{$role->value}-{$site->code}-{$batch}@example.invalid",
            'role' => $role->value, 'sites' => [$site->id], 'default_site_id' => $site->id,
            'locale' => 'fr', 'is_active' => true, 'password' => Str::password(40),
        ], $owner);
        $storekeeper = $staff(RoleName::Storekeeper, $dep, 'Magasinier dépôt');
        $managerM01 = $staff(RoleName::SiteManager, $m01, 'Responsable M01');
        $sellerM01 = $staff(RoleName::Seller, $m01, 'Vendeur M01');
        $sellerM02 = $staff(RoleName::Seller, $m02, 'Vendeur M02');

        // Catalogue.
        $tourisme = Category::query()->where('name', 'Tourisme')->firstOrFail();
        $oils = Category::query()->where('name', 'Huiles moteur')->firstOrFail();
        $tyre = fn (int $w, int $r, string $d, int $retail, int $pro) => tap(Product::query()->create([
            'kind' => ProductKind::Goods, 'category_id' => $tourisme->id, 'name' => "Pneu {$w}/{$r} R{$d} (démo)",
            'unit' => Unit::Piece, 'price_retail_cents' => $retail, 'price_pro_cents' => $pro, 'is_active' => true,
        ]), fn (Product $p) => $p->tyreSpec()->create(['width' => $w, 'aspect_ratio' => $r, 'rim_diameter' => $d, 'load_index' => '91', 'speed_index' => 'V']));
        $tyreA = $tyre(205, 55, '16', 80000, 70000);
        $tyreB = $tyre(195, 65, '15', 65000, 57000);
        $oil = Product::query()->create([
            'kind' => ProductKind::Goods, 'category_id' => $oils->id, 'name' => 'Huile 5W30 bidon 5 L (démo)',
            'unit' => Unit::Piece, 'price_retail_cents' => 25000, 'price_pro_cents' => 22000, 'is_active' => true,
        ]);

        // Purchase at the depot: order, two deliveries (DOT), partial payment of the supplier.
        $purchases = app(PurchaseActions::class);
        $supplier = Supplier::query()->create(['name' => "Fournisseur démo {$batch} (fictif)"]);
        $order = $purchases->order($supplier, $dep, [
            ['product_id' => $tyreA->id, 'quantity' => 40, 'unit_cost_cents' => 45000],
            ['product_id' => $tyreB->id, 'quantity' => 20, 'unit_cost_cents' => 38000],
            ['product_id' => $oil->id, 'quantity' => 30, 'unit_cost_cents' => 9000],
        ], $storekeeper);
        $purchases->receive($supplier, $dep, [
            ['product_id' => $tyreA->id, 'quantity' => 24, 'unit_cost_cents' => 45000, 'dot_week' => 10, 'dot_year' => 2026],
            ['product_id' => $oil->id, 'quantity' => 30, 'unit_cost_cents' => 9000],
        ], $storekeeper, "BL-DEMO-{$batch}-1", $order);
        $purchases->receive($supplier, $dep, [
            ['product_id' => $tyreA->id, 'quantity' => 16, 'unit_cost_cents' => 45000, 'dot_week' => 30, 'dot_year' => 2026],
            ['product_id' => $tyreB->id, 'quantity' => 20, 'unit_cost_cents' => 38000, 'dot_week' => 25, 'dot_year' => 2026],
        ], $storekeeper, "BL-DEMO-{$batch}-2", $order->fresh());
        $purchases->pay($supplier, 500000, 'transfer', "VIR-DEMO-{$batch}", $owner);

        // Transfers: to M01 and M02 (received), and one still in transit to M02.
        $send = app(SendTransferAction::class);
        $toM01 = $send->execute($dep, $m01, [$tyreA->id => 12, $tyreB->id => 6, $oil->id => 10], $storekeeper);
        $toM02 = $send->execute($dep, $m02, [$tyreA->id => 8, $tyreB->id => 4], $storekeeper);
        app(ReceiveTransferAction::class)->execute($toM01, $managerM01);
        app(ReceiveTransferAction::class)->execute($toM02, $owner);
        $inTransit = $send->execute($dep, $m02, [$tyreA->id => 4], $storekeeper);

        // Till of M01 opened with a float.
        $cash = app(CashRegister::class);
        $cash->setFloat($m01, 50000, $managerM01);

        // Sales: cash and card at M01, credit at M02 for a company with an opening balance.
        // $method null = everything on credit (customer account).
        $sell = function (Site $site, array $lines, User $seller, ?string $method, ?Customer $customer = null) {
            $draft = app(SaveSaleDraftAction::class)->execute(array_filter([
                'site_id' => $site->id, 'customer_id' => $customer?->id, 'lines' => $lines,
            ]), $seller);
            $payments = $method ? [['method' => $method, 'amount_cents' => $draft->total_ttc_cents]] : [];

            return app(ConfirmSaleAction::class)->execute($draft, $payments, $seller);
        };
        $walkIn = app(CreateCustomerAction::class)->execute(['name' => "Client démo {$batch} (fictif)", 'phone' => '0600000001', 'plate' => '12345-A-6'], $sellerM01);
        $cashSale = $sell($m01, [['product_id' => $tyreA->id, 'quantity' => 4], ['product_id' => $oil->id, 'quantity' => 1]], $sellerM01, 'cash', $walkIn);
        $cardSale = $sell($m01, [['product_id' => $tyreB->id, 'quantity' => 2]], $sellerM01, 'card');

        $company = Customer::query()->create([
            'type' => CustomerType::Company, 'name' => "Transports démo {$batch} (fictif)", 'phone' => '0600000002',
            'price_level' => PriceLevel::Pro, 'credit_limit_cents' => 2000000, 'created_by' => $owner->id,
        ]);
        $accounts = app(CustomerAccountActions::class);
        $accounts->addOpeningBalance($company, 300000, now()->subMonth()->toDateString(), 'Cahier (démo)', $owner);
        $creditSale = $sell($m02, [['product_id' => $tyreA->id, 'quantity' => 4]], $sellerM02, null, $company);
        $cheque = $accounts->receive($company, $m02, ['method' => 'cheque', 'amount_cents' => 200000, 'cheque_number' => "DEMO{$batch}", 'bank' => 'Banque démo'], $sellerM02);

        // A used tyre taken in at M01, with a photo, inspected; an expense with a photo.
        $usedPhoto = $this->photo('used-tyres', 'PNEU OCCASION DEMO');
        $usedTyres = app(UsedTyreActions::class);
        $used = $usedTyres->register(['width' => 205, 'aspect_ratio' => 55, 'rim_diameter' => '16', 'dot_week' => 12, 'dot_year' => 2024,
            'tread_depth_tenths' => 55, 'cost_cents' => 15000, 'price_cents' => 35000, 'photos' => [$usedPhoto], 'notes' => 'Démo'], $m01, UsedTyreOrigin::Supplier, $sellerM01);
        $usedTyres->inspect($used, collect(UsedTyreCheck::cases())->mapWithKeys(fn ($c) => [$c->value => true])->all(), $sellerM01, 55);
        $expense = $cash->addExpense($m01, ['category' => 'other', 'amount_cents' => 3000, 'description' => 'Fournitures atelier (démo)',
            'photo' => $this->photo('expenses', 'DEPENSE DEMO', 'documents')], $sellerM01);

        // Closing of the M01 till, counted exactly.
        $expected = $cash->summary($m01)['expected'];
        $day = $cash->close($m01, $expected, 'Clôture démo', $managerM01);

        return [
            'batch' => $batch,
            'products' => [$tyreA->sku => $tyreA->name, $tyreB->sku => $tyreB->name, $oil->sku => $oil->name],
            'purchase_order' => $order->number,
            'transfers' => ['received' => [$toM01->number, $toM02->number], 'in_transit' => $inTransit->number],
            'sales' => [
                'cash_m01' => [$cashSale->number, $cashSale->total_ttc_cents],
                'card_m01' => [$cardSale->number, $cardSale->total_ttc_cents],
                'credit_m02' => [$creditSale->number, $creditSale->total_ttc_cents],
            ],
            'company_outstanding_cents' => $company->fresh()->outstandingCents(),
            'cheque' => $cheque->id,
            'supplier_balance_cents' => $supplier->balanceCents(),
            'used_tyre' => $used->code,
            'photos' => $this->photos,
            'expense' => $expense->id,
            'm01_till' => ['expected_cents' => $day->expected_cents, 'difference_cents' => $day->difference_cents],
            'ids' => ['company' => $company->id, 'products' => [$tyreA->id, $tyreB->id, $oil->id], 'supplier' => $supplier->id],
        ];
    }

    private function site(string $code): Site
    {
        return Site::query()->where('code', $code)->firstOrFail();
    }

    /** A small, real JPEG on the disk an upload through the forms would use (receipts: private "documents"). */
    private function photo(string $folder, string $label, string $disk = 'public'): string
    {
        $image = imagecreatetruecolor(320, 200);
        imagefill($image, 0, 0, imagecolorallocate($image, 30, 90, 150));
        imagestring($image, 5, 20, 90, $label, imagecolorallocate($image, 255, 255, 255));
        ob_start();
        imagejpeg($image, null, 85);
        $bytes = ob_get_clean();

        $path = $folder.'/demo-'.Str::random(20).'.jpg';
        Storage::disk($disk)->put($path, $bytes);
        $this->photos[] = $path;

        return $path;
    }
}
