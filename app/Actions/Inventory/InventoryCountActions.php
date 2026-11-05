<?php

namespace App\Actions\Inventory;

use App\Enums\InventoryCountStatus;
use App\Enums\Permission;
use App\Enums\ProductKind;
use App\Exceptions\BusinessRuleException;
use App\Models\Category;
use App\Models\InventoryCount;
use App\Models\Product;
use App\Models\Site;
use App\Models\StockLevel;
use App\Models\User;
use App\Services\SequenceService;
use App\Services\Stock\StockService;
use Illuminate\Support\Facades\DB;

/**
 * Inventory count (§6.4): snapshot of expected quantities → counting on a
 * tablet/phone → submission → owner validation, which generates the
 * adjustments (difference = counted − expected at snapshot time).
 */
class InventoryCountActions
{
    public function __construct(private SequenceService $sequences, private StockService $stock) {}

    public function create(Site $site, User $user, ?Category $category = null, ?string $notes = null): InventoryCount
    {
        $this->authorize($user, Permission::InventoryCount, $site);

        return DB::transaction(function () use ($site, $user, $category, $notes) {
            $count = InventoryCount::query()->create([
                'number' => $this->sequences->nextDocumentNumber('INV'),
                'site_id' => $site->id,
                'category_id' => $category?->id,
                'status' => InventoryCountStatus::Draft,
                'created_by' => $user->id,
                'notes' => $notes,
            ]);

            $categoryIds = $category ? $this->categoryTree($category) : null;
            $levels = StockLevel::query()->where('site_id', $site->id)->pluck('quantity', 'product_id');

            Product::query()
                ->where('kind', ProductKind::Goods->value)
                ->where(fn ($q) => $q->where('is_active', true)->orWhereIn('id', $levels->filter()->keys()))
                ->when($categoryIds, fn ($q) => $q->whereIn('category_id', $categoryIds))
                ->orderBy('name')
                ->pluck('id')
                ->each(fn (int $productId) => $count->lines()->create([
                    'product_id' => $productId,
                    'expected_quantity' => (int) ($levels[$productId] ?? 0),
                ]));

            return $count;
        });
    }

    /** @param  array<int, int|null>  $counted  line_id => counted quantity */
    public function saveCounts(InventoryCount $count, array $counted, User $user): void
    {
        $this->authorize($user, Permission::InventoryCount, $count->site);
        $this->assertStatus($count, InventoryCountStatus::Draft);

        DB::transaction(function () use ($count, $counted) {
            foreach ($count->lines()->whereIn('id', array_keys($counted))->get() as $line) {
                $value = $counted[$line->id];
                if ($value !== null && (int) $value < 0) {
                    throw BusinessRuleException::make('inventory.errors.negative');
                }
                $line->update(['counted_quantity' => $value === null || $value === '' ? null : (int) $value]);
            }
        });
    }

    public function submit(InventoryCount $count, User $user): void
    {
        $this->authorize($user, Permission::InventoryCount, $count->site);
        $this->assertStatus($count, InventoryCountStatus::Draft);

        if (! $count->lines()->whereNotNull('counted_quantity')->exists()) {
            throw BusinessRuleException::make('inventory.errors.nothing_counted');
        }

        $count->update(['status' => InventoryCountStatus::Submitted, 'submitted_by' => $user->id, 'submitted_at' => now()]);
        activity('inventory')->causedBy($user)->performedOn($count)->event('inventory_submitted')
            ->tap(fn ($a) => $a->site_id = $count->site_id)->log('inventory_submitted');
    }

    /** Owner validation: generates one adjustment per counted difference. */
    public function approve(InventoryCount $count, User $user): void
    {
        $this->authorize($user, Permission::InventoryApprove, $count->site);

        DB::transaction(function () use ($count, $user) {
            $count = InventoryCount::query()->lockForUpdate()->with(['lines.product.category', 'site'])->findOrFail($count->id);
            $this->assertStatus($count, InventoryCountStatus::Submitted);

            $adjustments = [];
            foreach ($count->lines as $line) {
                $difference = $line->difference();
                if ($difference) {
                    $this->stock->adjust($line->product, $count->site, $difference, $user, $count->number, $count);
                    $adjustments[$line->product_id] = $difference;
                }
            }

            $count->update(['status' => InventoryCountStatus::Approved, 'approved_by' => $user->id, 'approved_at' => now()]);
            activity('inventory')->causedBy($user)->performedOn($count)->event('inventory_approved')
                ->withProperties(['adjustments' => $adjustments])
                ->tap(fn ($a) => $a->site_id = $count->site_id)->log('inventory_approved');
        });
    }

    public function cancel(InventoryCount $count, User $user): void
    {
        $this->authorize($user, Permission::InventoryCount, $count->site);
        if (! in_array($count->status, [InventoryCountStatus::Draft, InventoryCountStatus::Submitted], true)) {
            throw BusinessRuleException::make('inventory.errors.wrong_status');
        }

        $count->update(['status' => InventoryCountStatus::Cancelled]);
    }

    /** @return list<int> */
    private function categoryTree(Category $category): array
    {
        $ids = [$category->id];
        $frontier = [$category->id];
        while ($frontier !== []) {
            $frontier = Category::query()->whereIn('parent_id', $frontier)->pluck('id')->all();
            $ids = [...$ids, ...$frontier];
        }

        return $ids;
    }

    private function authorize(User $user, Permission $permission, Site $site): void
    {
        if (! $user->can($permission->value) || ! $user->canAccessSite($site)) {
            throw BusinessRuleException::make('app.errors.forbidden');
        }
    }

    private function assertStatus(InventoryCount $count, InventoryCountStatus $status): void
    {
        if ($count->status !== $status) {
            throw BusinessRuleException::make('inventory.errors.wrong_status');
        }
    }
}
