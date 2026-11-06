<?php

namespace App\Filament\Pages;

use App\Actions\Customers\CreateCustomerAction;
use App\Actions\Sales\ConfirmSaleAction;
use App\Actions\Sales\RequestSaleApprovalAction;
use App\Actions\Sales\SaveSaleDraftAction;
use App\Actions\Transfers\CreateReplenishmentRequestAction;
use App\Enums\ApprovalType;
use App\Enums\CompatibilityStatus;
use App\Enums\PaymentMethod;
use App\Enums\Permission;
use App\Enums\PriceLevel;
use App\Enums\ProductKind;
use App\Enums\RoleName;
use App\Enums\SiteType;
use App\Exceptions\ApprovalRequiredException;
use App\Exceptions\BusinessRuleException;
use App\Filament\Resources\Sales\SaleResource;
use App\Filament\Support\VehicleFields;
use App\Models\Customer;
use App\Models\CustomerVehicle;
use App\Models\OilFitment;
use App\Models\Product;
use App\Models\Sale;
use App\Models\Site;
use App\Models\TyreFitment;
use App\Models\UsedTyre;
use App\Models\User;
use App\Services\Approvals\ApprovalService;
use App\Services\Catalog\ProductSearch;
use App\Services\Sales\CustomerLookup;
use App\Services\Sales\SaleCalculator;
use App\Services\Sales\SaleDocuments;
use App\Support\Decimal;
use App\Support\Money;
use App\Support\TyreSize;
use BackedEnum;
use Filament\Notifications\Notification;
use Filament\Pages\Page;
use Filament\Panel;
use Filament\Support\Enums\Width;
use Filament\Support\Icons\Heroicon;
use Illuminate\Support\Collection;
use Livewire\Attributes\Url;

/**
 * The sale screen (§10.1): one page, big buttons, defaults everywhere
 * (user's site, cash, today). Business rules live in the Actions; this
 * component only collects the seller's choices. Prices shown come from
 * SaleCalculator, the same code that writes the invoice.
 */
class SaleScreen extends Page
{
    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedShoppingCart;

    protected static ?int $navigationSort = -10;

    protected string $view = 'filament.pages.sale-screen';

    protected Width|string|null $maxContentWidth = Width::Full;

    #[Url]
    public ?int $draft = null;

    public ?int $siteId = null;

    public string $customerSearch = '';

    public ?int $customerId = null;

    public ?int $vehicleId = null;

    public ?string $mileage = null;

    public string $search = '';

    /** @var list<array{product_id?: ?int, used_tyre_id?: ?int, quantity: int, discount: ?string}> */
    public array $cart = [];

    /** @var list<array{method: string, amount: ?string, cheque_number: ?string, bank: ?string, drawer: ?string, due_date: ?string}> */
    public array $payments = [];

    public bool $paymentsTouched = false;

    public ?string $cashGiven = null;

    /** @var list<int> */
    public array $technicianIds = [];

    public ?string $poNumber = null;

    public ?string $notes = null;

    public bool $showNewCustomer = false;

    /** @var array{name: string, phone: string, plate: string, vehicle_model_id: ?int} */
    public array $newCustomer = ['name' => '', 'phone' => '', 'plate' => '', 'vehicle_model_id' => null];

    public string $newPlate = '';

    /** @var list<string> */
    public array $neededApprovals = [];

    public string $approvalReason = '';

    public string $pin = '';

    public bool $approvalSent = false;

    public ?int $doneSaleId = null;

    public static function canAccess(): bool
    {
        return auth()->user()?->can(Permission::SalesCreate->value) ?? false;
    }

    public static function getNavigationLabel(): string
    {
        return __('sales.screen.title');
    }

    public static function getNavigationGroup(): ?string
    {
        return __('app.navigation.sales');
    }

    public static function getSlug(?Panel $panel = null): string
    {
        return 'vente';
    }

    public function getTitle(): string
    {
        return __('sales.screen.title');
    }

    public function mount(): void
    {
        $user = $this->user();
        $this->siteId = $user->default_site_id && $user->canAccessSite($user->default_site_id)
            ? $user->default_site_id
            : $this->sites()->first()?->id;

        if ($this->draft && ($sale = Sale::query()->with('lines')->visibleTo($user)->find($this->draft)) && $sale->isDraft()) {
            $this->loadDraft($sale);
        } else {
            $this->draft = null;
        }
    }

    // ---------------------------------------------------------------- customer

    public function selectCustomer(int $id): void
    {
        $customer = Customer::query()->with('vehicles')->findOrFail($id);
        $this->customerId = $customer->id;
        $this->customerSearch = '';
        $this->showNewCustomer = false;
        $this->vehicleId = $customer->vehicles->count() === 1 ? $customer->vehicles->first()->id : null;
        $this->resetApproval();
    }

    public function selectVehicle(int $customerId, int $vehicleId): void
    {
        $this->selectCustomer($customerId);
        $this->vehicleId = CustomerVehicle::query()->where('customer_id', $customerId)->findOrFail($vehicleId)->id;
    }

    public function clearCustomer(): void
    {
        $this->customerId = null;
        $this->vehicleId = null;
        $this->resetApproval();
    }

    public function openNewCustomer(): void
    {
        $term = trim($this->customerSearch);
        $digits = preg_replace('/\D/', '', $term);
        $this->newCustomer = [
            'name' => strlen($digits) >= 9 ? '' : $term,
            'phone' => strlen($digits) >= 9 ? $term : '',
            'plate' => '',
            'vehicle_model_id' => null,
        ];
        $this->showNewCustomer = true;
    }

    public function saveNewCustomer(CreateCustomerAction $action): void
    {
        try {
            $customer = $action->execute($this->newCustomer, $this->user());
        } catch (BusinessRuleException $e) {
            $this->addError('newCustomer', $e->getMessage());

            return;
        }

        $this->selectCustomer($customer->id);
        $this->vehicleId = $customer->vehicles->last()?->id ?? $this->vehicleId;
    }

    public function addVehicle(CreateCustomerAction $action): void
    {
        if (! $this->customerId || trim($this->newPlate) === '') {
            return;
        }
        abort_unless($this->user()->can(Permission::CustomersManage->value), 403);
        $vehicle = $action->addVehicle(Customer::query()->findOrFail($this->customerId), $this->newPlate);
        $this->vehicleId = $vehicle->id;
        $this->newPlate = '';
    }

    // ---------------------------------------------------------------- cart

    public function addProduct(int $id): void
    {
        foreach ($this->cart as $i => $item) {
            if (($item['product_id'] ?? null) === $id) {
                $this->cart[$i]['quantity']++;
                $this->afterCartChange();

                return;
            }
        }

        $product = Product::query()->with('tyreSpec')->findOrFail($id);
        // Tyres usually go by 2 or 4; one is the safe default, buttons change it.
        $this->cart[] = ['product_id' => $product->id, 'used_tyre_id' => null, 'quantity' => 1, 'discount' => null];
        $this->search = '';
        $this->afterCartChange();
    }

    public function addUsedTyre(int $id): void
    {
        if (collect($this->cart)->contains(fn ($i) => ($i['used_tyre_id'] ?? null) === $id)) {
            return;
        }
        $this->cart[] = ['product_id' => null, 'used_tyre_id' => $id, 'quantity' => 1, 'discount' => null];
        $this->search = '';
        $this->afterCartChange();
    }

    public function setQuantity(int $index, int $quantity): void
    {
        if (! isset($this->cart[$index]) || ! empty($this->cart[$index]['used_tyre_id'])) {
            return;
        }
        $this->cart[$index]['quantity'] = max(1, min(999, $quantity));
        $this->afterCartChange();
    }

    public function removeLine(int $index): void
    {
        unset($this->cart[$index]);
        $this->cart = array_values($this->cart);
        $this->afterCartChange();
    }

    public function updatedCart(): void
    {
        $this->afterCartChange();
    }

    public function updatedPayments(): void
    {
        $this->paymentsTouched = true;
    }

    public function updatedSiteId(): void
    {
        abort_unless($this->siteId && $this->user()->canAccessSite($this->siteId), 403);
        $this->technicianIds = [];
        $this->afterCartChange();
    }

    public function toggleTechnician(int $id): void
    {
        $this->technicianIds = in_array($id, $this->technicianIds, true)
            ? array_values(array_diff($this->technicianIds, [$id]))
            : [...$this->technicianIds, $id];
    }

    /** "Demander au dépôt" (§10.1): replenishment request for one product. */
    public function requestFromDepot(int $productId, CreateReplenishmentRequestAction $action): void
    {
        $depot = Site::query()->where('type', SiteType::Depot->value)->where('is_active', true)->first();
        $quantity = (int) (collect($this->cart)->firstWhere('product_id', $productId)['quantity'] ?? 1);

        try {
            $request = $action->execute($this->site(), $depot, [$productId => max(1, $quantity)], $this->user(), __('sales.screen.depot_note'));
        } catch (BusinessRuleException $e) {
            Notification::make()->danger()->title($e->getMessage())->send();

            return;
        }

        Notification::make()->success()->title(__('sales.screen.depot_requested', ['number' => $request->number]))->send();
    }

    // ---------------------------------------------------------------- payment

    public function payAllCash(): void
    {
        $this->payments = [$this->paymentRow(PaymentMethod::Cash, $this->total())];
        $this->paymentsTouched = false;
    }

    public function addPayment(string $method): void
    {
        $method = PaymentMethod::from($method);
        $this->syncPayments();
        $rest = max(0, $this->total() - $this->paidCents());
        // Splitting: the new method takes what is left; if nothing is left, it takes over the cash.
        if ($rest === 0 && count($this->payments) === 1 && $this->payments[0]['method'] === PaymentMethod::Cash->value) {
            $this->payments = [];
            $rest = $this->total();
        }
        $this->payments[] = $this->paymentRow($method, $rest);
        $this->paymentsTouched = true;
    }

    /** Everything that is not paid now stays on the customer's account. */
    public function payOnCredit(): void
    {
        $this->payments = [];
        $this->paymentsTouched = true;
    }

    public function removePayment(int $index): void
    {
        unset($this->payments[$index]);
        $this->payments = array_values($this->payments);
        $this->paymentsTouched = true;
    }

    // ---------------------------------------------------------------- confirm

    public function confirm(SaveSaleDraftAction $save, ConfirmSaleAction $confirm): void
    {
        $this->resetErrorBag();
        $this->syncPayments();

        try {
            $sale = $this->saveDraft($save);
            $sale = $confirm->execute($sale, $this->paymentInput(), $this->user(), $this->technicianIds);
        } catch (ApprovalRequiredException $e) {
            $this->neededApprovals = array_map(fn (ApprovalType $t) => $t->value, $e->types);

            return;
        } catch (BusinessRuleException $e) {
            $this->addError('sale', $e->getMessage());

            return;
        }

        $this->doneSaleId = $sale->id;
        $this->draft = null;
    }

    public function sendApproval(SaveSaleDraftAction $save, RequestSaleApprovalAction $request): void
    {
        $this->validate(['approvalReason' => 'required|string|min:3|max:500'], [], ['approvalReason' => __('sales.screen.approval_reason')]);

        $sale = $this->saveDraft($save);
        $request->execute($sale, $this->approvalTypes(), $this->approvalReason, $this->user(), $this->creditCents());
        $this->approvalSent = true;

        Notification::make()->success()->title(__('sales.screen.approval_sent'))
            ->body(__('sales.screen.approval_sent_help'))->send();
    }

    public function approveWithPin(SaveSaleDraftAction $save, RequestSaleApprovalAction $request, ApprovalService $approvals, ConfirmSaleAction $confirm): void
    {
        $this->validate(['pin' => 'required|digits_between:4,8']);
        $reason = trim($this->approvalReason) !== '' ? $this->approvalReason : __('sales.screen.pin_reason');

        try {
            $sale = $this->saveDraft($save);
            foreach ($request->execute($sale, $this->approvalTypes(), $reason, $this->user(), $this->creditCents()) as $approval) {
                if ($approval->isPending()) {
                    $approvals->decideWithPin($approval, $this->pin);
                }
            }
        } catch (BusinessRuleException $e) {
            $this->addError('pin', $e->getMessage());

            return;
        } finally {
            $this->pin = '';
        }

        $this->neededApprovals = [];
        $this->confirm($save, $confirm);
    }

    /** Back on the screen after the owner answered from his phone. */
    public function retry(SaveSaleDraftAction $save, ConfirmSaleAction $confirm): void
    {
        $this->neededApprovals = [];
        $this->approvalSent = false;
        $this->confirm($save, $confirm);
    }

    public function newSale(): void
    {
        $site = $this->siteId;
        $this->reset();
        $this->siteId = $site;
    }

    // ---------------------------------------------------------------- data for the view

    public function user(): User
    {
        return auth()->user();
    }

    public function site(): Site
    {
        return Site::query()->findOrFail($this->siteId);
    }

    /** @return Collection<int, Site> */
    public function sites(): Collection
    {
        return Site::query()->visibleTo($this->user())->where('is_active', true)->orderBy('code')->get();
    }

    public function customer(): ?Customer
    {
        return $this->customerId ? Customer::query()->with('vehicles.vehicleModel.make')->find($this->customerId) : null;
    }

    public function vehicle(): ?CustomerVehicle
    {
        return $this->vehicleId ? $this->customer()?->vehicles->firstWhere('id', $this->vehicleId) : null;
    }

    /** @return Collection<int, Customer> */
    public function customerResults(): Collection
    {
        return app(CustomerLookup::class)->search($this->customerSearch);
    }

    /** @return Collection<int, Product> */
    public function productResults(): Collection
    {
        if (mb_strlen(trim($this->search)) < 2) {
            return collect();
        }

        $query = Product::query()->where('is_active', true)->with(['stockLevels', 'tyreSpec', 'brand']);
        ProductSearch::apply($query, $this->search);

        return $query->orderBy('name')->limit(12)->get();
    }

    /** Used tyres of this site matching the size typed, or the code read from the QR label. */
    public function usedTyreResults(): Collection
    {
        $term = trim($this->search);
        if (mb_strlen($term) < 2) {
            return collect();
        }

        $query = UsedTyre::query()->sellable()->where('site_id', $this->siteId)->with('brand');
        if ($size = TyreSize::parse($term)) {
            $query->where('width', $size['width'])->where('rim_diameter', $size['rim_diameter'])
                ->when($size['aspect_ratio'], fn ($q, $v) => $q->where('aspect_ratio', $v));
        } elseif (preg_match('/^PO-?\d+$/i', $term)) {
            $query->where('code', 'ilike', '%'.preg_replace('/\D/', '', $term));
        } else {
            return collect();
        }

        return $query->limit(8)->get();
    }

    /** Services and bundles offered at this site: one button each. */
    public function quickButtons(): Collection
    {
        return Product::query()->where('is_active', true)
            ->whereIn('kind', [ProductKind::Service->value, ProductKind::Bundle->value])
            ->where(fn ($q) => $q->whereDoesntHave('sites')->orWhereHas('sites', fn ($s) => $s->whereKey($this->siteId)))
            ->orderBy('kind')->orderBy('name')->limit(24)->get();
    }

    /**
     * Products of the vehicle's verified compatibilities only (D-24), with stock.
     *
     * @return Collection<int, Product>
     */
    public function suggestions(): Collection
    {
        $modelId = $this->vehicle()?->vehicle_model_id;
        if (! $modelId) {
            return collect();
        }

        $ids = collect();
        foreach (TyreFitment::query()->where('model_id', $modelId)->where('status', CompatibilityStatus::Verified->value)->get() as $fitment) {
            $ids = $ids->merge($fitment->matchingProducts()->where('is_active', true)->pluck('id'));
        }
        foreach (OilFitment::query()->where('model_id', $modelId)->where('status', CompatibilityStatus::Verified->value)->whereNotNull('viscosity')->get() as $fitment) {
            $ids = $ids->merge($fitment->matchingProducts()->where('is_active', true)->pluck('id'));
        }

        return Product::query()->with(['stockLevels', 'tyreSpec', 'brand'])->whereKey($ids->unique())->orderBy('name')->limit(12)->get();
    }

    /** Priced lines for display, computed by the same code as the invoice. */
    public function preview(): array
    {
        if ($this->cart === [] || ! $this->siteId) {
            return ['lines' => [], 'error' => null];
        }

        $key = md5(json_encode([$this->cart, $this->siteId, $this->customerId, $this->vehicleId]));
        if ($this->previewCache !== null && $this->previewCache['key'] === $key) {
            return $this->previewCache['value'];
        }

        try {
            $value = ['lines' => app(SaleCalculator::class)->lines($this->lineInput(), $this->site(), $this->customer()?->price_level ?? PriceLevel::Retail, $this->vehicle()), 'error' => null];
        } catch (BusinessRuleException $e) {
            $value = ['lines' => [], 'error' => $e->getMessage()];
        }
        $this->previewCache = ['key' => $key, 'value' => $value];

        return $value;
    }

    /** Per-request memo (not sent to the browser). */
    private ?array $previewCache = null;

    public function total(): int
    {
        return (int) array_sum(array_column($this->preview()['lines'], 'total_ttc_cents'));
    }

    public function paidCents(): int
    {
        return (int) array_sum(array_map(fn ($p) => $this->cents($p['amount'] ?? null), $this->payments));
    }

    public function creditCents(): int
    {
        return max(0, $this->total() - $this->paidCents());
    }

    public function changeDue(): ?int
    {
        $cash = collect($this->payments)->firstWhere('method', PaymentMethod::Cash->value);
        if (! $cash || blank($this->cashGiven)) {
            return null;
        }

        return $this->cents($this->cashGiven) - $this->cents($cash['amount']);
    }

    public function hasServices(): bool
    {
        return collect($this->preview()['lines'])->contains(fn ($l) => $l['kind'] === 'service');
    }

    /** @return Collection<int, User> */
    public function technicians(): Collection
    {
        return User::query()->role(RoleName::Technician->value)->where('is_active', true)
            ->whereHas('sites', fn ($q) => $q->whereKey($this->siteId))->orderBy('name')->get();
    }

    public function stockHere(Product $product): int
    {
        $level = $product->stockLevels->firstWhere('site_id', $this->siteId);

        return $level ? $level->quantity - $level->reserved : 0;
    }

    public function stockElsewhere(Product $product): string
    {
        return $this->sites()->where('id', '!=', $this->siteId)
            ->map(fn (Site $s) => $s->code.' '.(int) ($product->stockLevels->firstWhere('site_id', $s->id)?->quantity ?? 0))
            ->implode(' · ');
    }

    public function depotStock(Product $product): int
    {
        $depot = Site::query()->where('type', SiteType::Depot->value)->value('id');

        return $depot === $this->siteId ? 0 : (int) ($product->stockLevels->firstWhere('site_id', $depot)?->quantity ?? 0);
    }

    public function canRequestFromDepot(): bool
    {
        return $this->user()->can(Permission::ReplenishmentRequest->value);
    }

    public function doneSale(): ?Sale
    {
        return $this->doneSaleId ? Sale::query()->with(['customer', 'workOrder'])->find($this->doneSaleId) : null;
    }

    public function whatsappUrl(Sale $sale): ?string
    {
        return app(SaleDocuments::class)->whatsappUrl($sale);
    }

    public function saleUrl(Sale $sale): string
    {
        return SaleResource::getUrl('view', ['record' => $sale]);
    }

    public function money(?int $cents): string
    {
        return Money::format($cents);
    }

    public function vehicleModelOptions(): array
    {
        return VehicleFields::modelOptions();
    }

    /** @return list<string> */
    public function approvalLabels(): array
    {
        return array_map(fn (ApprovalType $t) => $t->label(), $this->approvalTypes());
    }

    // ---------------------------------------------------------------- internals

    private function afterCartChange(): void
    {
        $this->resetApproval();
        if (! $this->paymentsTouched) {
            $this->payAllCash();
        }
    }

    private function syncPayments(): void
    {
        if (! $this->paymentsTouched) {
            $this->payAllCash();
        }
    }

    private function resetApproval(): void
    {
        $this->neededApprovals = [];
        $this->approvalSent = false;
        $this->resetErrorBag();
    }

    /** @return list<ApprovalType> */
    private function approvalTypes(): array
    {
        return array_map(fn (string $t) => ApprovalType::from($t), $this->neededApprovals);
    }

    private function saveDraft(SaveSaleDraftAction $save): Sale
    {
        $existing = $this->draft ? Sale::query()->visibleTo($this->user())->find($this->draft) : null;
        $sale = $save->execute([
            'site_id' => $this->siteId,
            'customer_id' => $this->customerId,
            'customer_vehicle_id' => $this->vehicleId,
            'mileage' => filled($this->mileage) ? (int) preg_replace('/\D/', '', $this->mileage) : null,
            'po_number' => $this->poNumber,
            'notes' => $this->notes,
            'lines' => $this->lineInput(),
        ], $this->user(), $existing?->isDraft() ? $existing : null);
        $this->draft = $sale->id;

        return $sale;
    }

    private function loadDraft(Sale $sale): void
    {
        $this->siteId = $sale->site_id;
        $this->customerId = $sale->customer_id;
        $this->vehicleId = $sale->customer_vehicle_id;
        $this->mileage = $sale->mileage ? (string) $sale->mileage : null;
        $this->poNumber = $sale->po_number;
        $this->notes = $sale->notes;

        $cart = [];
        foreach ($sale->lines as $line) {
            if ($line->bundle_id) {
                if (! collect($cart)->contains(fn ($c) => ($c['product_id'] ?? null) === $line->bundle_id)) {
                    $cart[] = ['product_id' => $line->bundle_id, 'used_tyre_id' => null, 'quantity' => 1, 'discount' => $line->discount_bp ? Decimal::basisPointsToPercent($line->discount_bp) : null];
                }

                continue;
            }
            $cart[] = [
                'product_id' => $line->product_id,
                'used_tyre_id' => $line->used_tyre_id,
                'quantity' => $line->quantity,
                'discount' => $line->discount_bp ? Decimal::basisPointsToPercent($line->discount_bp) : null,
            ];
        }
        $this->cart = $cart;
        $this->payAllCash();
    }

    private function lineInput(): array
    {
        return array_map(fn (array $item) => array_filter([
            'product_id' => $item['product_id'] ?? null,
            'used_tyre_id' => $item['used_tyre_id'] ?? null,
            'quantity' => (int) ($item['quantity'] ?? 1),
            'discount_bp' => preg_match('/^\d{1,3}([.,]\d{1,2})?$/', trim((string) ($item['discount'] ?? '')))
                ? min(10000, Decimal::percentToBasisPoints(str_replace(',', '.', trim((string) $item['discount'])))) : 0,
        ], fn ($v) => $v !== null), $this->cart);
    }

    private function paymentInput(): array
    {
        return array_map(fn (array $p) => [
            'method' => $p['method'],
            'amount_cents' => $this->cents($p['amount'] ?? null),
            'cheque_number' => $p['cheque_number'] ?? null,
            'bank' => $p['bank'] ?? null,
            'drawer' => $p['drawer'] ?? null,
            'due_date' => filled($p['due_date'] ?? null) ? $p['due_date'] : null,
        ], $this->payments);
    }

    private function paymentRow(PaymentMethod $method, int $cents): array
    {
        return ['method' => $method->value, 'amount' => Decimal::fromCents($cents), 'cheque_number' => null, 'bank' => null, 'drawer' => null, 'due_date' => null];
    }

    private function cents(?string $value): int
    {
        $value = trim(str_replace([' ', "\u{202F}", "\u{00A0}"], '', (string) $value));
        if ($value === '' || ! preg_match('/^\d+([.,]\d{1,2})?$/', $value)) {
            return 0;
        }

        return Decimal::toCents($value);
    }
}
