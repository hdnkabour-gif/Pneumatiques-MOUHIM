<?php

namespace App\Enums;

/**
 * Every permission checked server-side. New phases add cases here, then
 * `php artisan db:seed --class=RolesAndPermissionsSeeder` syncs them.
 */
enum Permission: string
{
    // Sites
    case SitesView = 'sites.view';
    case SitesManage = 'sites.manage';
    case SitesViewAll = 'sites.view_all';

    // Users & roles
    case UsersView = 'users.view';
    case UsersManage = 'users.manage';
    case RolesManage = 'roles.manage';

    // Settings
    case SettingsManage = 'settings.manage';

    // Activity log
    case ActivityLogView = 'activity_log.view';

    // Catalog (phase 2)
    case ProductsView = 'products.view';
    case ProductsManage = 'products.manage';
    case CostsView = 'costs.view'; // purchase costs, average cost, margins, stock value

    // Stock (phase 2)
    case StockView = 'stock.view';
    case StockReceive = 'stock.receive';
    case StockAdjust = 'stock.adjust'; // request adjustments / damaged (approval required)
    case TransfersSend = 'transfers.send';
    case TransfersReceive = 'transfers.receive';
    case ReplenishmentRequest = 'replenishment.request';
    case InventoryCount = 'inventory.count';
    case InventoryApprove = 'inventory.approve';
    case UsedTyresManage = 'used_tyres.manage';
    case UsedTyresInspect = 'used_tyres.inspect';

    // Approvals
    case ApprovalsDecide = 'approvals.decide';

    // Vehicles & compatibilities (D-48)
    case CompatibilitiesView = 'compatibilities.view';
    case CompatibilitiesManage = 'compatibilities.manage';   // reference data, manual entry, import
    case CompatibilitiesVerify = 'compatibilities.verify';   // mark a compatibility as verified

    // Sales (phase 3)
    case CustomersView = 'customers.view';
    case CustomersManage = 'customers.manage';
    case SalesCreate = 'sales.create';       // sale screen, confirm a sale, take the payment
    case SalesView = 'sales.view';           // invoices of his sites
    case WorkOrdersManage = 'work_orders.manage'; // create, assign technicians, move statuses (D-53)

    public function label(): string
    {
        return __('permissions.'.$this->value);
    }

    public function group(): string
    {
        return __('permissions.groups.'.explode('.', $this->value)[0]);
    }
}
