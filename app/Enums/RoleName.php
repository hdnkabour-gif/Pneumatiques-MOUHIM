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
            self::SiteManager => [Permission::SitesView, Permission::UsersView],
            self::Seller, self::Storekeeper => [Permission::SitesView],
            self::Technician => [],
        };
    }
}
