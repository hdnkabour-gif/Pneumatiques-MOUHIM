<?php

namespace App\Models;

use App\Enums\CustomerType;
use App\Enums\PriceLevel;
use App\Enums\RefundMethod;
use App\Enums\SaleKind;
use App\Enums\SaleStatus;
use App\Support\Normalize;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Collection;
use Spatie\Activitylog\Models\Concerns\LogsActivity;
use Spatie\Activitylog\Support\LogOptions;

class Customer extends Model
{
    use HasFactory, LogsActivity;

    protected $fillable = [
        'type', 'name', 'phone', 'email', 'ice', 'tax_id', 'trade_register', 'address', 'city', 'price_level',
        'credit_limit_cents', 'payment_terms_days', 'requires_po_number', 'notes', 'is_active', 'created_by',
    ];

    protected function casts(): array
    {
        return [
            'type' => CustomerType::class,
            'price_level' => PriceLevel::class,
            'credit_limit_cents' => 'integer',
            'requires_po_number' => 'boolean',
            'is_active' => 'boolean',
        ];
    }

    protected static function booted(): void
    {
        static::saving(fn (Customer $c) => $c->phone_normalized = Normalize::phone($c->phone));
    }

    public function vehicles(): HasMany
    {
        return $this->hasMany(CustomerVehicle::class);
    }

    public function sales(): HasMany
    {
        return $this->hasMany(Sale::class);
    }

    /**
     * Amount still owed on confirmed sales. Phase 4 replaces this with the
     * full customer account (payments, credit notes, opening balance).
     */
    public function outstandingCents(): int
    {
        $owed = (int) $this->sales()->where('status', SaleStatus::Confirmed->value)
            ->whereIn('kind', [SaleKind::Invoice->value, SaleKind::DeliveryNote->value])
            ->whereNull('recap_invoice_id') // a note grouped in a recap invoice is counted there
            ->sum('credit_cents');
        $credited = (int) $this->sales()->where('status', SaleStatus::Confirmed->value)
            ->where('kind', SaleKind::CreditNote->value)->where('refund_method', RefundMethod::Account->value)
            ->sum('total_ttc_cents');

        return $owed - $credited;
    }

    /** Exchange vouchers of this customer with money left on them. */
    public function vouchers(): Collection
    {
        return $this->sales()->where('status', SaleStatus::Confirmed->value)->where('kind', SaleKind::CreditNote->value)
            ->where('refund_method', RefundMethod::Voucher->value)->get()
            ->filter(fn (Sale $s) => $s->voucherBalanceCents() > 0)->values();
    }

    public function getActivitylogOptions(): LogOptions
    {
        return LogOptions::defaults()->logFillable()->logOnlyDirty()->dontLogEmptyChanges();
    }
}
