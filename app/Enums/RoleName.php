<?php

namespace App\Enums;

/**
 * Default roles (cahier des charges §3). Permissions attached to each role
 * remain editable by the owner, except the owner role which always has
 * full access.
 */
enum RoleName: string
{
    case Owner = 'owner';
    case SiteManager = 'site_manager';
    case Seller = 'seller';
    case Storekeeper = 'storekeeper';
    case Technician = 'technician';

    public function label(): string
    {
        return __('roles.'.$this->value);
    }

    /**
     * Default permissions granted when roles are first created.
     *
     * @return list<Permission>
     */
    public function defaultPermissions(): array
    {
        return match ($this) {
            self::Owner => Permission::cases(),
            self::SiteManager => [
                Permission::SitesView, Permission::UsersView, Permission::ProductsView, Permission::StockView,
                Permission::StockAdjust, Permission::TransfersReceive, Permission::ReplenishmentRequest,
                Permission::InventoryCount, Permission::UsedTyresManage, Permission::UsedTyresInspect,
                Permission::CompatibilitiesView,
                Permission::CustomersView, Permission::CustomersManage, Permission::SalesCreate,
                Permission::SalesView, Permission::WorkOrdersManage, Permission::PaymentsReceive, Permission::ChequesManage, Permission::ExpensesCreate, Permission::CashClose,
            ],
            self::Seller => [
                Permission::SitesView, Permission::ProductsView, Permission::StockView,
                Permission::ReplenishmentRequest, Permission::UsedTyresManage, Permission::UsedTyresInspect,
                Permission::CompatibilitiesView,
                Permission::CustomersView, Permission::CustomersManage, Permission::SalesCreate,
                Permission::SalesView, Permission::WorkOrdersManage, Permission::PaymentsReceive, Permission::ExpensesCreate,
            ],
            self::Storekeeper => [
                Permission::SitesView, Permission::ProductsView, Permission::StockView, Permission::StockReceive, Permission::PurchasesManage,
                Permission::StockAdjust, Permission::TransfersSend, Permission::TransfersReceive,
                Permission::InventoryCount, Permission::UsedTyresManage,
            ],
            self::Technician => [],
        };
    }
}
