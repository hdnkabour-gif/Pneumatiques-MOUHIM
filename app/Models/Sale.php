<?php

namespace App\Models;

use App\Enums\PriceLevel;
use App\Enums\SaleStatus;
use Illuminate\Database\Eloquent\Attributes\Scope;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;
use LogicException;

/**
 * A sale. Drafts are prepared on the sale screen; confirmation (ConfirmSaleAction)
 * gives the invoice number, takes the stock out and freezes the document:
 * a confirmed sale is never modified nor deleted (D-15, also a database trigger).
 */
class Sale extends Model
{
    protected $guarded = ['id'];

    protected function casts(): array
    {
        return [
            'status' => SaleStatus::class,
            'price_level' => PriceLevel::class,
            'confirmed_at' => 'datetime',
            'approvals' => 'array',
            'total_ht_cents' => 'integer',
            'total_vat_cents' => 'integer',
            'total_ttc_cents' => 'integer',
            'discount_cents' => 'integer',
            'paid_cents' => 'integer',
            'credit_cents' => 'integer',
        ];
    }

    protected static function booted(): void
    {
        static::deleting(function (Sale $sale) {
            if ($sale->status !== SaleStatus::Draft) {
                throw new LogicException('A confirmed sale cannot be deleted: issue a credit note.');
            }
        });
    }

    public function isDraft(): bool
    {
        return $this->status === SaleStatus::Draft;
    }

    public function site(): BelongsTo
    {
        return $this->belongsTo(Site::class);
    }

    public function customer(): BelongsTo
    {
        return $this->belongsTo(Customer::class);
    }

    public function vehicle(): BelongsTo
    {
        return $this->belongsTo(CustomerVehicle::class, 'customer_vehicle_id');
    }

    public function seller(): BelongsTo
    {
        return $this->belongsTo(User::class, 'seller_id');
    }

    public function lines(): HasMany
    {
        return $this->hasMany(SaleLine::class)->orderBy('sort_order');
    }

    public function payments(): BelongsToMany
    {
        return $this->belongsToMany(Payment::class, 'payment_allocations')->withPivot('amount_cents');
    }

    public function workOrder(): HasOne
    {
        return $this->hasOne(WorkOrder::class);
    }

    #[Scope]
    protected function visibleTo(Builder $query, User $user): void
    {
        if (! $user->canSeeAllSites()) {
            $query->whereIn('site_id', $user->siteIds());
        }
    }

    /** Gross margin, or null when any line has an unknown cost (never guessed, CLAUDE.md §4). */
    public function marginCents(): ?int
    {
        $margin = 0;
        foreach ($this->lines as $line) {
            if ($line->kind === 'goods') {
                if ($line->unit_cost_cents === null) {
                    return null;
                }
                $margin += $line->total_ht_cents - $line->unit_cost_cents * $line->quantity;
            } else {
                $margin += $line->total_ht_cents;
            }
        }

        return $margin;
    }
}
