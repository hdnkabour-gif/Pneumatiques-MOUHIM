<?php

namespace App\Filament\Resources\PurchaseOrders\Pages;

use App\Actions\Purchases\PurchaseActions;
use App\Exceptions\BusinessRuleException;
use App\Filament\Resources\PurchaseOrders\PurchaseOrderResource;
use App\Models\Site;
use App\Models\Supplier;
use Filament\Resources\Pages\CreateRecord;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class CreatePurchaseOrder extends CreateRecord
{
    protected static string $resource = PurchaseOrderResource::class;

    /** DOT "2425" → week 24 of 2025 (empty when unreadable, never invented). */
    public static function dot(?string $value): array
    {
        $digits = preg_replace('/\D/', '', (string) $value);

        return strlen($digits) === 4 ? ['dot_week' => (int) substr($digits, 0, 2), 'dot_year' => 2000 + (int) substr($digits, 2)] : [];
    }

    protected function handleRecordCreation(array $data): Model
    {
        $actions = app(PurchaseActions::class);
        $supplier = Supplier::query()->findOrFail($data['supplier_id']);
        $site = Site::query()->findOrFail($data['site_id']);
        $lines = array_map(fn ($l) => ['product_id' => (int) $l['product_id'], 'quantity' => (int) $l['quantity'],
            'unit_cost_cents' => (int) $l['unit_cost_cents'], ...self::dot($l['dot'] ?? null)], array_values($data['lines'] ?? []));

        try {
            return DB::transaction(function () use ($actions, $supplier, $site, $lines, $data) {
                $order = $actions->order($supplier, $site, $lines, auth()->user(), $data['expected_at'] ?? null);
                if ($data['received_now'] ?? false) {
                    $actions->receive($supplier, $site, $lines, auth()->user(), $data['invoice_ref'] ?? null, $order);
                }

                return $order;
            });
        } catch (BusinessRuleException $e) {
            throw ValidationException::withMessages(['data.lines' => $e->getMessage()]);
        }
    }

    protected function getRedirectUrl(): string
    {
        return $this->getResource()::getUrl('view', ['record' => $this->getRecord()]);
    }
}
