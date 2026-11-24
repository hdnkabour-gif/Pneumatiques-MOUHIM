<?php

namespace App\Console\Commands;

use App\Services\InvoiceNumberingApproval;
use App\Services\SettingsService;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Read-only, non-destructive inventory before replacing demonstration data.
 * This command never deletes, edits, archives or marks a record as a demo.
 */
#[Signature('app:go-live-audit {--strict : Fail if operational rows remain or protected base is missing}')]
#[Description('Inspect production readiness and business table counts without modifying any data')]
class GoLiveAuditCommand extends Command
{
    private const BUSINESS_TABLES = [
        // Customer accounts, documents, payments, workshop and tills.
        'customers', 'customer_vehicles', 'sales', 'sale_lines',
        'issued_invoice_documents', 'payments', 'payment_allocations',
        'customer_opening_balances', 'work_orders', 'user_work_order',
        'cash_days', 'expenses',
        // Shop-specific products and all dependent specifications/pivots.
        'products', 'product_references', 'tyre_specs', 'oil_specs',
        'battery_specs', 'filter_specs', 'lighting_specs',
        'product_site_thresholds', 'service_price_tiers',
        'service_consumables', 'product_site', 'bundle_items',
        // Stock ledger and inventory (not reference catalogue).
        'stock_movements', 'stock_levels', 'stock_reservations', 'tyre_lots',
        'used_tyres', 'used_tyre_inspections', 'inventory_counts',
        'inventory_count_lines', 'oil_drums', 'oil_drum_movements',
        // Supply chain, workshop equipment and site goals.
        'suppliers', 'purchase_orders', 'purchase_order_lines',
        'purchase_receipts', 'purchase_receipt_lines', 'supplier_payments',
        'replenishment_requests', 'replenishment_request_lines',
        'transfers', 'transfer_lines', 'transfer_line_lots',
        'approval_requests', 'equipment', 'equipment_events', 'site_objectives',
    ];

    private const PRESERVED_TABLES = [
        'users', 'sites', 'settings', 'roles', 'permissions', 'activity_log',
        'categories', 'brands', 'tyre_catalogue_references',
        'oil_catalogue_references', 'vehicle_makes', 'vehicle_models',
        'vehicle_generations', 'vehicle_engines', 'compatibility_sources',
        'tyre_fitments', 'oil_fitments', 'sequences', 'model_has_roles',
        'model_has_permissions', 'role_has_permissions', 'push_subscriptions',
        'notifications',
    ];

    public function handle(): int
    {
        $counts = [];
        foreach ([...self::BUSINESS_TABLES, ...self::PRESERVED_TABLES] as $table) {
            if (Schema::hasTable($table)) {
                $counts[$table] = DB::table($table)->count();
            }
        }

        $settings = app(SettingsService::class);
        $this->info('READ-ONLY GO-LIVE AUDIT — NO DATA HAS BEEN DELETED');
        $this->table(['Business table', 'Rows'], array_map(
            fn ($table) => [$table, $counts[$table] ?? 'not installed'],
            self::BUSINESS_TABLES,
        ));
        $this->table(['Preserved table', 'Rows'], array_map(
            fn ($table) => [$table, $counts[$table] ?? 'not installed'],
            self::PRESERVED_TABLES,
        ));
        $this->line('Invoice numbering pattern: '.(string) $settings->get('documents.number_pattern'));
        $this->line('Invoice numbering prefix: '.(string) $settings->get('documents.prefix.invoice'));
        $this->line('Invoice padding: '.(int) $settings->get('documents.number_padding'));
        $numbering = app(InvoiceNumberingApproval::class)->problem();
        $this->line('Invoice numbering approval: '.($numbering === null ? 'PASS' : 'FAIL — '.$numbering));
        $this->warn('This command is a diagnostic only. It does not certify that existing business records are demonstrations.');
        $this->warn('Do not enter real sales before the owner confirms the next invoice number and the cleanup has passed on a restored copy.');

        // A count signature is useful for post-cleanup comparison, not a cryptographic
        // checksum of all row contents: changes with unchanged counts are not detected.
        $signature = hash('sha256', json_encode($counts, JSON_THROW_ON_ERROR));
        $this->line('Business/reference count signature: '.$signature);

        if (! $this->option('strict')) {
            return self::SUCCESS;
        }

        $nonEmpty = array_filter(self::BUSINESS_TABLES, fn ($table) => ($counts[$table] ?? null) !== 0);
        $missing = array_filter(self::PRESERVED_TABLES, fn ($table) => ! array_key_exists($table, $counts));
        $ownerCount = DB::table('users')
            ->join('model_has_roles', 'model_has_roles.model_id', '=', 'users.id')
            ->join('roles', 'roles.id', '=', 'model_has_roles.role_id')
            ->where('users.is_active', true)
            ->where('roles.name', 'owner')
            ->distinct()->count('users.id');
        $validBase = ($counts['sites'] ?? null) === 3 && $ownerCount > 0
            && ($counts['categories'] ?? 0) > 0 && ($counts['brands'] ?? 0) > 0;
        $pendingJobs = DB::table('jobs')->count();

        if ($nonEmpty || $missing || ! $validBase || $pendingJobs > 0 || $numbering !== null) {
            $this->error('GO_LIVE_NOT_READY: operational data remain, protected references are missing, or the queue is not empty.');
            $this->line('Nonempty or missing business tables: '.implode(', ', $nonEmpty));
            $this->line('Missing protected tables: '.implode(', ', $missing));
            $this->line('Invoice numbering approved: '.($numbering === null ? 'yes' : 'no'));
            $this->line('Sites: '.($counts['sites'] ?? 'missing').'; active owners: '.$ownerCount.'; queued jobs: '.$pendingJobs);

            return self::FAILURE;
        }

        $this->info('GO_LIVE_CLEAN_BASE_VERIFIED: business rows zero; core users/sites/references preserved.');

        return self::SUCCESS;
    }
}
