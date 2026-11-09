<?php

namespace App\Services\Imports;

use App\Actions\Money\CustomerAccountActions;
use App\Actions\Stock\ReceiveStockAction;
use App\Enums\CustomerType;
use App\Enums\Permission;
use App\Enums\PriceLevel;
use App\Enums\ProductKind;
use App\Enums\Unit;
use App\Models\Brand;
use App\Models\Category;
use App\Models\Customer;
use App\Models\Product;
use App\Models\Site;
use App\Models\User;
use App\Support\Decimal;
use App\Support\Normalize;
use App\Support\TyreSize;
use Illuminate\Support\Facades\DB;
use OpenSpout\Common\Entity\Row;
use OpenSpout\Reader\XLSX\Reader;
use OpenSpout\Writer\XLSX\Writer;

/**
 * Start-up imports (§17): template → fill in → check every line → import.
 * Nothing is written while one line is wrong; existing records are never
 * overwritten (customers by phone / ICE, products by SKU are skipped).
 */
class StartupImport
{
    public const TYPES = [
        'customers' => ['Nom', 'Téléphone', 'Type (particulier/société)', 'ICE', 'Ville', 'Plafond crédit (MAD)', 'Solde d\'ouverture (MAD)', 'Date du solde (JJ/MM/AAAA)', 'Immatriculation'],
        'products' => ['Nom', 'Catégorie', 'Marque', 'Prix particulier TTC', 'Prix pro TTC', 'Prix revendeur TTC', 'Coût d\'achat HT', 'Unité (piece/can/kit/set/litre)', 'Dimension pneu (205/55 R16 91V)', 'Référence fabricant', 'SKU (vide = automatique)'],
        'stock' => ['SKU', 'Site (DEP/M01/M02)', 'Quantité', 'Coût unitaire HT', 'DOT (ex. 2425)'],
    ];

    public function template(string $type, string $path): void
    {
        $writer = new Writer;
        $writer->openToFile($path);
        $writer->getCurrentSheet()->setName($type);
        $writer->addRow(Row::fromValues(self::TYPES[$type]));
        $writer->close();
    }

    /** @return array{rows: list<array<int, mixed>>, errors: list<string>} */
    public function check(string $type, string $path): array
    {
        $rows = $this->read($path);
        $errors = [];
        foreach ($rows as $i => $r) {
            $line = $i + 2;
            foreach ($this->validate($type, $r) as $e) {
                $errors[] = __('imports.line', ['line' => $line, 'error' => $e]);
            }
        }
        if ($rows === []) {
            $errors[] = __('imports.empty');
        }

        return ['rows' => $rows, 'errors' => $errors];
    }

    /** @return array{created: int, skipped: int} */
    public function import(string $type, array $rows, User $user): array
    {
        return DB::transaction(function () use ($type, $rows, $user) {
            $created = $skipped = 0;
            foreach ($rows as $r) {
                $this->{'import'.ucfirst($type)}($r, $user) ? $created++ : $skipped++;
            }
            activity('imports')->causedBy($user)->event('startup_import')->withProperties(compact('type', 'created', 'skipped'))->log('startup_import');

            return ['created' => $created, 'skipped' => $skipped];
        });
    }

    private function read(string $path): array
    {
        $reader = new Reader;
        $reader->open($path);
        $rows = [];
        foreach ($reader->getSheetIterator() as $sheet) {
            foreach ($sheet->getRowIterator() as $n => $row) {
                $cells = array_map(fn ($v) => is_string($v) ? trim($v) : ($v instanceof \DateTimeInterface ? $v->format('d/m/Y') : $v), $row->toArray());
                if ($n === 1 || array_filter($cells, fn ($v) => $v !== null && $v !== '') === []) {
                    continue;
                }
                $rows[] = $cells;
            }
            break; // first sheet only
        }
        $reader->close();

        return $rows;
    }

    private function money(mixed $v): ?int
    {
        if ($v === null || $v === '') {
            return null;
        }
        $s = str_replace([' ', "\u{00A0}"], '', (string) $v);

        return preg_match('/^\d+([.,]\d{1,2})?$/', $s) ? Decimal::toCents($s) : -1;
    }

    /** @return list<string> */
    private function validate(string $type, array $r): array
    {
        $e = [];
        $money = function (int $i) use (&$e, $r, $type) {
            if ($this->money($r[$i] ?? null) === -1) {
                $e[] = __('imports.errors.amount', ['column' => self::TYPES[$type][$i]]);
            }
        };

        if ($type === 'customers') {
            if (blank($r[0] ?? null)) {
                $e[] = __('imports.errors.required', ['column' => 'Nom']);
            }
            if (strlen(preg_replace('/\D/', '', (string) ($r[1] ?? ''))) < 9) {
                $e[] = __('imports.errors.phone');
            }
            $money(5);
            $money(6);
            if (($this->money($r[6] ?? null) ?? 0) > 0 && ! \DateTime::createFromFormat('d/m/Y', (string) ($r[7] ?? ''))) {
                $e[] = __('imports.errors.date');
            }
        } elseif ($type === 'products') {
            if (blank($r[0] ?? null) && blank($r[8] ?? null)) {
                $e[] = __('imports.errors.required', ['column' => 'Nom']);
            }
            if (! Category::query()->whereRaw('lower(name) = ?', [mb_strtolower((string) ($r[1] ?? ''))])->exists()) {
                $e[] = __('imports.errors.category', ['name' => $r[1] ?? '']);
            }
            if (($this->money($r[3] ?? null) ?? 0) <= 0) {
                $e[] = __('imports.errors.required', ['column' => 'Prix particulier TTC']);
            }
            $money(4);
            $money(5);
            $money(6);
            if (filled($r[7] ?? null) && ! Unit::tryFrom((string) $r[7])) {
                $e[] = __('imports.errors.unit');
            }
            if (filled($r[8] ?? null) && ! TyreSize::parse((string) $r[8])) {
                $e[] = __('customers.errors.tyre_size');
            }
        } else {
            $product = Product::query()->where('sku', (string) ($r[0] ?? ''))->first();
            if (! $product || ! $product->isStocked()) {
                $e[] = __('imports.errors.sku', ['sku' => $r[0] ?? '']);
            }
            if (! Site::query()->where('code', strtoupper((string) ($r[1] ?? '')))->exists()) {
                $e[] = __('imports.errors.site');
            }
            if (! is_numeric($r[2] ?? null) || (int) $r[2] <= 0 || (int) $r[2] != $r[2]) {
                $e[] = __('imports.errors.quantity');
            }
            $money(3);
            if (filled($r[4] ?? null) && ! preg_match('/^(0[1-9]|[1-4]\d|5[0-3])\d{2}$/', (string) $r[4])) {
                $e[] = __('stock.errors.invalid_dot');
            }
        }

        return $e;
    }

    private function importCustomers(array $r, User $user): bool
    {
        $phone = Normalize::phone((string) $r[1]);
        $ice = filled($r[3] ?? null) ? (string) $r[3] : null;
        if (Customer::query()->where('phone_normalized', $phone)->whereRaw('lower(name) = ?', [mb_strtolower((string) $r[0])])->exists()
            || ($ice && Customer::query()->where('ice', $ice)->exists())) {
            return false;
        }
        $company = str_starts_with(mb_strtolower((string) ($r[2] ?? '')), 'soc') || $ice;
        $customer = Customer::query()->create([
            'name' => (string) $r[0], 'phone' => (string) $r[1], 'ice' => $ice, 'city' => $r[4] ?? null,
            'type' => $company ? CustomerType::Company : CustomerType::Individual,
            'price_level' => $company ? PriceLevel::Pro : PriceLevel::Retail,
            // Only the owner gives credit (D-54): limits are imported only from the owner's file.
            'credit_limit_cents' => $user->can(Permission::ApprovalsDecide->value) ? $this->money($r[5] ?? null) : null,
            'created_by' => $user->id,
        ]);
        if (($opening = $this->money($r[6] ?? null)) > 0) {
            app(CustomerAccountActions::class)->addOpeningBalance($customer, $opening,
                \DateTime::createFromFormat('d/m/Y', (string) $r[7])->format('Y-m-d'), 'Import', $user);
        }
        if (filled($r[8] ?? null)) {
            $customer->vehicles()->create(['plate' => mb_strtoupper((string) $r[8])]);
        }

        return true;
    }

    private function importProducts(array $r, User $user): bool
    {
        if (filled($r[10] ?? null) && Product::query()->where('sku', (string) $r[10])->exists()) {
            return false;
        }
        $category = Category::query()->whereRaw('lower(name) = ?', [mb_strtolower((string) $r[1])])->first();
        $size = filled($r[8] ?? null) ? TyreSize::parse((string) $r[8]) : null;
        $brand = filled($r[2] ?? null) ? Brand::query()->firstOrCreate(['name' => (string) $r[2]]) : null;
        $name = filled($r[0] ?? null) ? (string) $r[0] : trim(($brand?->name ?? '').' '.TyreSize::format($size['width'], $size['aspect_ratio'], $size['rim_diameter'], $size['load_index'], $size['speed_index']));

        $product = Product::query()->create([
            'sku' => filled($r[10] ?? null) ? (string) $r[10] : null,
            'kind' => ProductKind::Goods, 'category_id' => $category->id, 'brand_id' => $brand?->id, 'name' => $name,
            'unit' => Unit::tryFrom((string) ($r[7] ?? '')) ?? Unit::Piece,
            'price_retail_cents' => $this->money($r[3]), 'price_pro_cents' => $this->money($r[4] ?? null),
            'price_reseller_cents' => $this->money($r[5] ?? null), 'reference_cost_cents' => $this->money($r[6] ?? null),
            'manufacturer_ref' => $r[9] ?? null, 'is_active' => true,
        ]);
        if ($size) {
            $product->tyreSpec()->create(['width' => $size['width'], 'aspect_ratio' => $size['aspect_ratio'], 'rim_diameter' => $size['rim_diameter'],
                'load_index' => $size['load_index'], 'speed_index' => $size['speed_index']]);
        }

        return true;
    }

    private function importStock(array $r, User $user): bool
    {
        $dot = filled($r[4] ?? null) ? (string) $r[4] : null;
        app(ReceiveStockAction::class)->execute(
            Product::query()->where('sku', (string) $r[0])->firstOrFail(), Site::query()->where('code', strtoupper((string) $r[1]))->firstOrFail(),
            (int) $r[2], $this->money($r[3] ?? null), $user, opening: true,
            dotWeek: $dot ? (int) substr($dot, 0, 2) : null, dotYear: $dot ? 2000 + (int) substr($dot, 2) : null, reason: 'Import stock d\'ouverture',
        );

        return true;
    }
}
