<?php

namespace App\Console\Commands;

use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use App\Services\SettingsService;

/**
 * Read-only, non-destructive inventory before replacing demonstration data.
 * This command never deletes, edits, archives or marks a record as a demo.
 */
#[Signature('app:go-live-audit')]
#[Description('Inspect production readiness and business table counts without modifying any data')]
class GoLiveAuditCommand extends Command
{
    private const BUSINESS_TABLES = [
        'customers', 'customer_vehicles', 'sales', 'sale_lines',
        'issued_invoice_documents', 'payments', 'payment_allocations',
        'customer_opening_balances', 'work_orders', 'user_work_order',
        'cash_days', 'expenses', 'suppliers', 'purchase_orders',
        'purchase_order_lines', 'purchase_receipts', 'purchase_receipt_lines',
        'supplier_payments', 'replenishment_requests', 'replenishment_request_lines',
        'transfers', 'transfer_lines', 'transfer_line_lots',
        'stock_movements', 'stock_levels', 'stock_reservations', 'tyre_lots',
        'used_tyres', 'used_tyre_inspections', 'inventory_counts',
        'inventory_count_lines', 'approval_requests', 'oil_drums',
        'oil_drum_movements', 'equipment', 'equipment_events', 'site_objectives',
    ];

    private const PRESERVED_TABLES = [
        'users', 'sites', 'settings', 'roles', 'permissions', 'activity_log',
        'categories', 'brands', 'tyre_catalogue_references',
        'oil_catalogue_references', 'vehicle_makes', 'vehicle_models',
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
        $this->warn('This command is a diagnostic only. It does not certify that existing business records are demonstrations.');
        $this->warn('Do not enter real sales before the owner confirms the next invoice number and the cleanup has passed on a restored copy.');

        return self::SUCCESS;
    }
}
