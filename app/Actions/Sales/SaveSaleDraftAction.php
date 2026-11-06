<?php

namespace App\Actions\Sales;

use App\Enums\Permission;
use App\Enums\PriceLevel;
use App\Enums\SaleKind;
use App\Enums\SaleStatus;
use App\Exceptions\BusinessRuleException;
use App\Models\Customer;
use App\Models\CustomerVehicle;
use App\Models\Sale;
use App\Models\Site;
use App\Models\User;
use App\Services\Sales\SaleCalculator;
use Illuminate\Support\Facades\DB;

/**
 * Saves the cart of the sale screen as a draft (prices from the catalogue).
 * A draft takes no stock and has no number; it can be changed freely.
 *
 * @phpstan-type DraftInput array{site_id: int, customer_id?: ?int, customer_vehicle_id?: ?int, mileage?: ?int, po_number?: ?string, notes?: ?string, lines: list<array<string, mixed>>}
 */
class SaveSaleDraftAction
{
    public function __construct(private SaleCalculator $calculator) {}

    /** @param  DraftInput  $data */
    public function execute(array $data, User $user, ?Sale $draft = null): Sale
    {
        $site = Site::query()->findOrFail($data['site_id']);

        if (! $user->can(Permission::SalesCreate->value) || ! $user->canAccessSite($site)) {
            throw BusinessRuleException::make('app.errors.forbidden');
        }

        if (empty($data['lines'])) {
            throw BusinessRuleException::make('sales.errors.empty');
        }

        $customer = filled($data['customer_id'] ?? null) ? Customer::query()->findOrFail($data['customer_id']) : null;
        $vehicle = filled($data['customer_vehicle_id'] ?? null)
            ? CustomerVehicle::query()->where('customer_id', $customer?->id)->findOrFail($data['customer_vehicle_id'])
            : null;
        $level = $customer?->price_level ?? PriceLevel::Retail;

        $rows = $this->calculator->lines($data['lines'], $site, $level, $vehicle);
        $kind = SaleKind::tryFrom((string) ($data['kind'] ?? 'invoice')) ?? SaleKind::Invoice;

        if (array_sum(array_column($rows, 'total_ttc_cents')) < 0) {
            throw BusinessRuleException::make('sales.errors.trade_in_exceeds');
        }
        if ($kind === SaleKind::CreditNote) {
            throw BusinessRuleException::make('app.errors.forbidden');
        }
        if ($kind === SaleKind::DeliveryNote && ! $customer) {
            throw BusinessRuleException::make('sales.errors.note_needs_customer');
        }
        if ($kind === SaleKind::Quote && collect($rows)->contains(fn ($r) => $r['kind'] === 'trade_in')) {
            throw BusinessRuleException::make('sales.errors.quote_trade_in');
        }

        return DB::transaction(function () use ($data, $user, $draft, $site, $customer, $vehicle, $level, $rows, $kind) {
            if ($draft) {
                $draft = Sale::query()->lockForUpdate()->findOrFail($draft->id);
                if (! $draft->isDraft()) {
                    throw BusinessRuleException::make('sales.errors.not_draft');
                }
                $draft->lines()->delete();
            } else {
                $draft = new Sale(['status' => SaleStatus::Draft, 'seller_id' => $user->id]);
            }

            $draft->fill([
                'site_id' => $site->id,
                'kind' => $kind,
                'customer_id' => $customer?->id,
                'customer_vehicle_id' => $vehicle?->id,
                'mileage' => $data['mileage'] ?? null,
                'price_level' => $level,
                'po_number' => filled($data['po_number'] ?? null) ? trim($data['po_number']) : null,
                'notes' => $data['notes'] ?? null,
                'total_ht_cents' => array_sum(array_column($rows, 'total_ht_cents')),
                'total_vat_cents' => array_sum(array_column($rows, 'total_vat_cents')),
                'total_ttc_cents' => array_sum(array_column($rows, 'total_ttc_cents')),
                'discount_cents' => array_sum(array_map(fn ($r) => $r['list_price_ttc_cents'] * $r['quantity'] - $r['total_ttc_cents'], $rows)),
                'quote_id' => $draft->quote_id ?? null,
            ])->save();

            $draft->lines()->createMany($rows);

            return $draft->load('lines');
        });
    }
}
