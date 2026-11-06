<?php

/*
| Application-level defaults. Values marked "setting" are only defaults:
| the live value is stored in the `settings` table and edited by the owner
| (see App\Services\SettingsService). Nothing fiscal is hard-coded (D-19, D-30).
*/

return [
    'default_locale' => 'fr',
    'locales' => ['fr', 'ar'],
    'timezone' => 'Africa/Casablanca',
    'currency' => 'MAD',

    // Default values of every setting key.
    'settings' => [
        'company.name' => 'PNEU MOUHIM',
        'company.address' => null,
        'company.city' => null,
        'company.phone' => null,
        'company.email' => null,
        'company.logo' => null,
        'legal.ice' => null,
        'legal.if' => null,
        'legal.rc' => null,
        'legal.patente' => null,
        'legal.cnss' => null,
        'bank.name' => null,
        'bank.rib' => null,

        // VAT rate in basis points (2000 = 20 %). Copied on every document line (D-19).
        'tax.vat_rate_bp' => 2000,

        // D-30: provisional, pending the accountant's validation.
        'tax.stamp_duty_enabled' => false,
        'tax.stamp_duty_rate_bp' => 25,          // 0.25 %
        'tax.stamp_duty_threshold_cents' => 0,
        'tax.used_tyre_vat_enabled' => true,
        'tax.trade_in_vat_enabled' => true,
        'documents.legal_mentions' => null,
        'documents.number_pattern' => '{PREFIX}-{YEAR}-{NUMBER}',
        'documents.number_padding' => 6,
        // D-49: prefix of each numbered document, editable by the owner.
        'documents.prefix.invoice' => 'FA',
        'documents.prefix.quote' => 'DV',
        'documents.prefix.delivery_note' => 'BL',
        'documents.prefix.work_note' => 'BT',
        'documents.prefix.credit_note' => 'AV',
        'documents.prefix.receipt' => 'RC',
        'documents.prefix.work_order' => 'OT',
        'documents.prefix.transfer' => 'TR',
        'documents.prefix.replenishment' => 'DR',
        'documents.prefix.inventory' => 'INV',

        // Sales (phase 3): caps in basis points / cents; above them the owner approves (D-18, D-52).
        'sales.discount_cap_bp.seller' => 500,
        'sales.discount_cap_bp.site_manager' => 1000,
        'sales.trade_in_cap_cents.tyre' => 20000,
        'sales.trade_in_cap_cents.battery' => 15000,
        'documents.invoice_footer' => null,
        'sales.quote_validity_days' => 15,

        // Stock (phase 2)
        'stock.tyre_age_alert_months' => 36,          // D-42
        'stock.dormant_days' => 90,                   // no exit for X days = dormant (§6.2)
        'used_tyres.set_tread_tolerance_tenths' => 10, // D-43: 1.0 mm
    ],

    'backup' => [
        'disk' => env('BACKUP_DISK', 'backups'),
        'keep_days' => (int) env('BACKUP_KEEP_DAYS', 30),
    ],
];
