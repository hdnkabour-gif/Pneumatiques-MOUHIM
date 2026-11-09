<?php

namespace App\Filament\Pages;

use App\Enums\ApprovalStatus;
use App\Enums\PaymentMethod;
use App\Enums\Permission;
use App\Enums\QuoteStatus;
use App\Enums\ReplenishmentStatus;
use App\Enums\RoleName;
use App\Enums\SaleKind;
use App\Enums\SaleStatus;
use App\Enums\TransferStatus;
use App\Enums\WorkOrderStatus;
use App\Models\ApprovalRequest;
use App\Models\Customer;
use App\Models\Payment;
use App\Models\ReplenishmentRequest;
use App\Models\Sale;
use App\Models\Transfer;
use App\Models\WorkOrder;
use App\Services\SettingsService;
use App\Support\Money;
use App\Support\Normalize;
use BackedEnum;
use Filament\Pages\Page;
use Filament\Panel;
use Filament\Support\Icons\Heroicon;
use Illuminate\Support\Collection;

/**
 * « Ce qui reste » (§11): everything still open, on one phone screen —
 * money owed, cheques to cash, open quotes, cars in the workshop,
 * transfers in transit, pending requests and approvals.
 */
class WhatRemains extends Page
{
    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedClipboardDocumentCheck;

    protected static ?int $navigationSort = -9;

    protected string $view = 'filament.pages.what-remains';

    public static function canAccess(): bool
    {
        $user = auth()->user();

        return $user && ($user->can(Permission::ApprovalsDecide->value) || $user->hasRole(RoleName::SiteManager->value));
    }

    public static function getSlug(?Panel $panel = null): string
    {
        return 'reste';
    }

    public static function getNavigationLabel(): string
    {
        return __('remains.title');
    }

    public function getTitle(): string
    {
        return __('remains.title');
    }

    /** Customers who owe money, the biggest first, with the age of the oldest unpaid document. */
    public function receivables(): Collection
    {
        $unpaid = Sale::query()->visibleTo(auth()->user())->with('customer')
            ->where('status', SaleStatus::Confirmed->value)->whereIn('kind', [SaleKind::Invoice->value, SaleKind::DeliveryNote->value])
            ->whereNull('recap_invoice_id')->where('credit_cents', '>', 0)->whereNotNull('customer_id')->get()->groupBy('customer_id');

        return $unpaid->map(function (Collection $sales) {
            /** @var Customer $customer */
            $customer = $sales->first()->customer;
            $due = $customer->outstandingCents();

            return (object) [
                'customer' => $customer,
                'due' => $due,
                'days' => (int) $sales->min('confirmed_at')->diffInDays(now()),
                'count' => $sales->count(),
                'whatsapp' => Normalize::whatsappUrl($customer->phone, __('remains.whatsapp_reminder', [
                    'name' => $customer->name, 'amount' => Money::format($due), 'company' => app(SettingsService::class)->get('company.name'),
                ], 'fr')),
            ];
        })->filter(fn ($r) => $r->due > 0)->sortByDesc('due')->values();
    }

    public function cheques(): Collection
    {
        return Payment::query()->with('customer')->where('method', PaymentMethod::Cheque->value)->where('status', 'received')
            ->when(! auth()->user()->canSeeAllSites(), fn ($q) => $q->whereIn('site_id', auth()->user()->siteIds()))
            ->orderByRaw('due_date nulls first')->get();
    }

    public function quotes(): Collection
    {
        return Sale::query()->visibleTo(auth()->user())->with('customer')->where('kind', SaleKind::Quote->value)
            ->where('quote_status', QuoteStatus::Sent->value)->orderBy('valid_until')->get();
    }

    public function workshop(): Collection
    {
        return WorkOrder::query()->visibleTo(auth()->user())->with(['vehicle', 'site'])
            ->whereNotIn('status', [WorkOrderStatus::Delivered->value, WorkOrderStatus::Cancelled->value])->orderBy('created_at')->get();
    }

    public function transfers(): Collection
    {
        return Transfer::query()->visibleTo(auth()->user())->with(['fromSite', 'toSite'])->where('status', TransferStatus::InTransit->value)->get();
    }

    public function requests(): Collection
    {
        return ReplenishmentRequest::query()->with('site')->where('status', ReplenishmentStatus::Pending->value)
            ->when(! auth()->user()->canSeeAllSites(), fn ($q) => $q->whereIn('site_id', auth()->user()->siteIds()))->get();
    }

    public function approvals(): Collection
    {
        return auth()->user()->can(Permission::ApprovalsDecide->value)
            ? ApprovalRequest::query()->with('requester')->where('status', ApprovalStatus::Pending->value)->latest()->get()
            : collect();
    }

    public function money(int $cents): string
    {
        return Money::format($cents);
    }
}
